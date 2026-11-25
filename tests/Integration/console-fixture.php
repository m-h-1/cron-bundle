<?php
declare(strict_types=1);

switch ($argv[1]) {
    case 'timeout':
        echo 'before timeout', PHP_EOL;
        sleep(3);
        echo 'after timeout', PHP_EOL;
        break;
    case 'failure':
        fwrite(STDERR, 'child error');
        exit(7);
    case 'success':
        echo 'success', PHP_EOL;
        break;
}
