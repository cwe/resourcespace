<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(401);
    exit('Access denied - Command line only!');
}

// typesense_search: the generation rebuild command. The 0.1 reindex is gone; the rebuild arrives with B8.
fwrite(STDERR, "typesense_search: the generation rebuild is not built yet.\n");
exit(2);
