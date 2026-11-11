<?php

/**
 * collection_readable() and the external access key in the request.
 *
 * A key makes a collection readable only when it was issued for that collection, see collection_access_key_valid().
 * Each case asks, as a user holding only the 's' permission, whether another user's private collection is readable
 * with the given key in the request.
 */

command_line_only();

$saved_000885 = [
    "userpermissions"     => $userpermissions,
    "k"                   => $_GET["k"] ?? null,
    "share_access_cookie" => $_COOKIE["share_access"] ?? null,
];
$other_user_885 = 999885;

$private_res_885 = create_resource(1, 0);
$private_col_885 = create_collection($other_user_885, "Test 885 private", 0, 0, 0, false);
add_resource_to_collection($private_res_885, $private_col_885);
$own_res_885 = create_resource(1, 0);
$own_col_885 = create_collection($userref, "Test 885 own", 0, 0, 0, false);
add_resource_to_collection($own_res_885, $own_col_885);

// Keys made the way sharing a collection makes them, and an upload link written directly
$share_password_885 = "test885 password";
$key_private_885    = generate_collection_access_key($private_col_885, 0, "", 0);
$key_own_885        = generate_collection_access_key($own_col_885, 0, "", 0);
$key_expired_885    = generate_collection_access_key($private_col_885, 0, "", 0, "2000-01-01");
$key_password_885   = generate_collection_access_key($private_col_885, 0, "", 0, "", "", $share_password_885);
$key_upload_885     = "t885upload";
ps_query(
    "INSERT INTO external_access_keys (access_key, collection, user, usergroup, upload, date) VALUES (?, ?, ?, ?, 1, NOW())",
    ["s", $key_upload_885, "i", $private_col_885, "i", $userref, "i", $usergroup]
);
// The cookie a visitor holds once they have given the share password, see check_share_password()
$password_hash_885   = hash("sha256", $key_password_885 . $share_password_885 . $scramble_key);
$password_cookie_885 = hash("sha256", date("Ymd") . $key_password_885 . $password_hash_885 . $scramble_key);

$cases_885 = [
    ["name" => "no key", "key" => "", "expected" => false],
    ["name" => "a key that does not exist", "key" => "t885nokey", "expected" => false],
    ["name" => "the key of the collection", "key" => $key_private_885, "expected" => true],
    // The user can make this key themselves, by sharing a collection of their own
    ["name" => "the key of another collection", "key" => $key_own_885, "expected" => false],
    ["name" => "an expired key of the collection", "key" => $key_expired_885, "expected" => false],
    ["name" => "an upload link key of the collection", "key" => $key_upload_885, "expected" => false],
    ["name" => "a password protected key of the collection", "key" => $key_password_885, "expected" => false],
    ["name" => "a password protected key of the collection with the share access cookie",
        "key" => $key_password_885, "cookie" => $password_cookie_885, "expected" => true],
    // A key that does not exist takes nothing away from the owner
    ["name" => "own collection with a key that does not exist", "key" => "t885nokey",
        "collection" => $own_col_885, "expected" => true],
];

$errors_000885 = [];
$userpermissions = ["s"];
foreach ($cases_885 as $case) {
    unset($GLOBALS["get_collection_cache"], $GLOBALS["collection_readable_key_check_cache"], $_COOKIE["share_access"]);
    $_GET["k"] = $case["key"];
    if (isset($case["cookie"])) {
        $_COOKIE["share_access"] = $case["cookie"];
    }
    $readable = collection_readable($case["collection"] ?? $private_col_885);
    if ($readable !== $case["expected"]) {
        $errors_000885[] = $case["name"] . ": expected " . ($case["expected"] ? "readable" : "not readable");
    }
}

// Tear down
$userpermissions = $saved_000885["userpermissions"];
unset($_GET["k"], $_COOKIE["share_access"]);
if ($saved_000885["k"] !== null) {
    $_GET["k"] = $saved_000885["k"];
}
if ($saved_000885["share_access_cookie"] !== null) {
    $_COOKIE["share_access"] = $saved_000885["share_access_cookie"];
}
ps_query("DELETE FROM external_access_keys WHERE collection IN (?, ?)", ["i", $private_col_885, "i", $own_col_885]);
delete_collection($private_col_885);
delete_collection($own_col_885);
delete_resource($private_res_885);
delete_resource($own_res_885);
unset($GLOBALS["get_collection_cache"], $GLOBALS["collection_readable_key_check_cache"]);
unset($cases_885, $readable);

if (count($errors_000885) > 0) {
    echo "ERROR - " . implode("; ", $errors_000885) . " ";
    return false;
}
return true;
