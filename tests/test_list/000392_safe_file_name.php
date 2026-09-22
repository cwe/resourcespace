<?php

command_line_only();

$use_cases = [
    [
        'name' => 'Filename containing only valid characters.',
        'input' => ['my_file1_2-3'],
        'expected' => 'my_file1_2-3',
    ],
    [
        'name' => 'Filename containing invalid characters.',
        'input' => ['my.file1/!Í'],
        'expected' => 'myfile1',
    ],
    [
        'name' => 'Filename containing spaces.',
        'input' => ['my file1'],
        'expected' => 'my_file1',
    ],
    [
        'name' => 'Filename containing only valid characters.',
        'input' => ['my_file1_2-3', true],
        'expected' => 'my_file1_2-3',
    ],
    [
        'name' => 'Filename containing invalid characters.',
        'input' => ['my.file1/!', true],
        'expected' => 'myfile1',
    ],
    [
        'name' => 'Filename containing spaces.',
        'input' => ['my file1ă', true],
        'expected' => 'my_file1ă',
    ],
    [
        'name' => 'Filename containing only valid characters including non ascii characters.',
        'input' => ['test-ă_fileÍ', true],
        'expected' => 'test-ă_fileÍ',
    ],
];
foreach ($use_cases as $uc) {
    $result = safe_file_name(...$uc['input']);
    if ($uc['expected'] !== $result) {
        echo "Use case: {$uc['name']} - ";

        test_log("- result   = {$result}");
        test_log("- expected = {$uc['expected']}");

        return false;
    }
}

// Tear down
unset($use_cases, $result);

return true;
