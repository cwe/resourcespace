<?php

/**
 * typesense_search: date values as the index holds them.
 *
 * Core stores a date field value as text: YYYY-MM-DD with 00 for an unknown month or day, a time HH:MM[:SS] after a
 * full date, a leading minus for a BCE year. A search value matches as a prefix of that text and a range compares
 * as strings, the end with " 23:59:59" appended. The index keeps the prefixes a search can name (_q) and the value's
 * digits as one fourteen-digit integer (_k) whose order is the string order of every well-formed value (Q3.4); the
 * bounds of a range are built the same way. Epochs are read in PHP's default time zone, which must be MySQL's.
 */

/**
 * Split a value in core's date form into its parts as written, or null for anything else.
 *
 * @param string $value Value without surrounding space
 *
 * @return array|null year, month, day, hour, minute, second; the absent parts null
 */
function typesense_search_dates_match(string $value): ?array
{
    $form = '/^(-?\d{4})(?:-(\d{2})(?:-(\d{2})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?)?)?$/D';
    if (preg_match($form, $value, $parts, PREG_UNMATCHED_AS_NULL) !== 1) {
        return null;
    }

    return array(
        'year' => $parts[1],
        'month' => $parts[2],
        'day' => $parts[3],
        'hour' => $parts[4],
        'minute' => $parts[5],
        'second' => $parts[6],
    );
}

/**
 * The fourteen-digit key of date parts: their digits in YYYYMMDDHHMMSS order, an absent month or day as 00.
 *
 * @param array  $parts As typesense_search_dates_match() returns them
 * @param string $fill  The six time digits of a value without a time: 000000, or 235959 for a range end
 *
 * @return int|null Null for a BCE year, which is not digits
 */
function typesense_search_dates_key(array $parts, string $fill = '000000'): ?int
{
    if ($parts['year'][0] === '-') {
        return null;
    }
    $time = $parts['hour'] === null ? $fill : $parts['hour'] . $parts['minute'] . ($parts['second'] ?? '00');

    return (int)(str_pad($parts['year'] . $parts['month'] . $parts['day'], 8, '0') . $time);
}

/**
 * A stored date value as the index holds it: the representations for _q (the prefixes at the group boundaries,
 * which core's LIKE "<value>%" answers), the key for _k, the precision (year, month, day; none without a real
 * year) and the parts as written (null for 00 or absent). A value that is not in core's form gets nothing.
 *
 * @param string|null $value Stored value
 *
 * @return array q, k, precision, year, month, day
 */
function typesense_search_dates_parse(?string $value): array
{
    $parsed = array('q' => array(), 'k' => null, 'precision' => 'none', 'year' => null, 'month' => null, 'day' => null);
    $parts = typesense_search_dates_match(trim((string)$value));
    if ($parts === null) {
        return $parsed;
    }

    $parsed['q'][] = $parts['year'];
    if ($parts['month'] !== null) {
        $parsed['q'][] = $parts['year'] . '-' . $parts['month'];
    }
    if ($parts['day'] !== null) {
        $parsed['q'][] = $parts['year'] . '-' . $parts['month'] . '-' . $parts['day'];
    }
    $parsed['k'] = typesense_search_dates_key($parts);

    $year = (int)$parts['year'];
    $month = (int)$parts['month'];
    $day = (int)$parts['day'];
    $parsed['year'] = $year === 0 ? null : $year;
    $parsed['month'] = $month === 0 ? null : $month;
    $parsed['day'] = $day === 0 ? null : $day;
    if ($year !== 0) {
        // A non-real day (2024-02-31) has month precision; a BCE day is checked against year 1.
        $real_month = $month >= 1 && $month <= 12;
        $real_day = $real_month && $day >= 1 && checkdate($month, $day, max($year, 1));
        $parsed['precision'] = $real_day ? 'day' : ($real_month ? 'month' : 'year');
    }

    return $parsed;
}

/**
 * The keys of a range as core compares its strings: name >= start and name <= "<end> 23:59:59". A bound is the text
 * the search carries (YYYY, YYYY-MM or YYYY-MM-DD; the form's -31 and EDTF's -00 and -99 parts included), null or
 * empty for an open side. Null when a bound given is not a date, so the search can be left to core.
 *
 * @param string|null $start Start bound
 * @param string|null $end   End bound
 *
 * @return array|null start and end keys, each null when open
 */
function typesense_search_dates_range_bounds(?string $start, ?string $end): ?array
{
    $bounds = array('start' => null, 'end' => null);
    foreach (array('start' => $start, 'end' => $end) as $side => $bound) {
        $bound = trim((string)$bound);
        if ($bound === '') {
            continue;
        }
        $parts = typesense_search_dates_match($bound);
        if ($parts === null || $parts['hour'] !== null) {
            return null;
        }
        $bounds[$side] = typesense_search_dates_key($parts, $side === 'end' ? '235959' : '000000');
        if ($bounds[$side] === null) {
            return null;
        }
    }

    return $bounds;
}

/**
 * The date sort key: "0:" for NULL, "1:" for an empty value, "2:<value>" otherwise, so that a string sort of the
 * key is core's sort of the resource table's field<$date_field> column (NULL first ascending, then empty, then
 * the values).
 *
 * @param string|null $value The column value
 *
 * @return string
 */
function typesense_search_dates_sort_key(?string $value): string
{
    if ($value === null) {
        return '0:';
    }

    return $value === '' ? '1:' : '2:' . $value;
}

/**
 * The epoch of a MySQL datetime column value (creation_date, modified, date_added), read in PHP's default time
 * zone: core stores NOW() in MySQL's zone, so the two must agree. A time in a DST gap is moved forward and an
 * ambiguous one resolved by PHP's date library. Null for NULL, empty, a zero date or anything that is not a real
 * date and time.
 *
 * @param string|null $value Column value, YYYY-MM-DD[ HH:MM[:SS]]
 *
 * @return int|null
 */
function typesense_search_dates_timestamp(?string $value): ?int
{
    $form = '/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?$/D';
    if (preg_match($form, trim((string)$value), $parts) !== 1) {
        return null;
    }
    $parts = array_pad($parts, 7, '00');
    if (
        !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])
        || (int)$parts[4] > 23 || (int)$parts[5] > 59 || (int)$parts[6] > 59
    ) {
        return null;
    }
    $time = DateTime::createFromFormat(
        '!Y-m-d H:i:s',
        vsprintf('%s-%s-%s %s:%s:%s', array_slice($parts, 1)),
        new DateTimeZone(date_default_timezone_get())
    );

    return $time === false ? null : $time->getTimestamp();
}

/**
 * The cut-off of core's recent-days limit, creation_date > (curdate() - interval N day): midnight in PHP's default
 * time zone, N days before the local date of $now.
 *
 * @param int      $days Days back
 * @param int|null $now  Epoch to count from; null for the current time
 *
 * @return int
 */
function typesense_search_dates_day_limit(int $days, ?int $now = null): int
{
    $cutoff = new DateTime('@' . ($now ?? time()));
    $cutoff->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $cutoff->setTime(0, 0, 0);
    $cutoff->modify(sprintf('%+d days', -$days));

    return $cutoff->getTimestamp();
}
