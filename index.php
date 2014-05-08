<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
    <title>AttentiveBot</title>
    <style>
    
    .message {
      font: 12px menlo, monospace;
      white-space: pre;
    }
    
    </style>
  </head>
  <body>
<?php

require_once 'config.php';
require_once 'twitter.php';
require_once 'database.php';
require_once 'attentivebot.php';

set_time_limit(0);

$app = array(
  TWITTER_APP_KEY,
  TWITTER_APP_SECRET
);
$token = array(
  TWITTER_TOKEN_KEY,
  TWITTER_TOKEN_SECRET
);

date_default_timezone_set(TIMEZONE);

$twitter = new Twitter($app, $token);
$db = new Database(DB_PATH, DB_SETUP_PATH);
$bot = new AttentiveBot($twitter, $db);
$bot->main();

?>
<script>
    
var messages = document.getElementsByClassName('reset');
var message, match, reset;
var timeout = 60; // in seconds
for (var i = 0; i < messages.length; i++) {
  message = messages[i];
  match = message.className.match(/reset-(\d+)/);
  if (match) {
    timeout = parseInt(match[1], 10) + 5;
  }
}
setTimeout(function() {
  location.reload();
}, timeout * 1000);

</script>
</body>
</html> 
