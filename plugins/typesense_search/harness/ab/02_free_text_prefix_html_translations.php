<?php
// Free text, continued: the last-word prefix, HTML tags, translated options.
require __DIR__ . '/fixture.php';
fx_index();
echo "\n################ A/B: FREE TEXT, second pass ################\n";
ab('A40 uppercase', 'SUNSET');
ab('A45 last word is a prefix of other words', 'car');
ab('A46 two words, last one a prefix', 'red car');
ab('A47 two words, first one a prefix', 'car red');
ab('A48 word beyond the first 500 characters (keyword exists elsewhere)', 'zeppelin');
ab('A49 html tag name (keyword exists elsewhere)', 'strong');
ab('A50 html: word after a tag (keyword exists elsewhere)', 'hello');
ab('A51 translated option + another word', 'germany gate');
ab('A52 translated option, last word', 'gate germany');
ab('A53 translated option, second language + word', 'allemagne gate');
ab('A54 quoted phrase, last word prefix?', '"red spo"');
ab('A55 negative prefix?', 'launch -shi');
ab('A56 keyword then negative', 'red -spo');
