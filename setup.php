<?php

$db->query("
  CREATE TABLE tweets (
    screen_name VARCHAR(32),
    tweet_id VARCHAR(255),
    created DATETIME
  )
");

$db->query("
  CREATE TABLE following (
    screen_name VARCHAR(32),
    following TEXT,
    updated DATETIME
  )
");

$db->query("
  CREATE TABLE pending (
    screen_name VARCHAR(32),
    updated DATETIME
  )
");

$db->query("
  CREATE INDEX tweets_index
  ON tweets
  (screen_name, tweet_id, created)
");

$db->query("
  CREATE INDEX following_index
  ON following
  (screen_name, updated)
");

$db->query("
  CREATE INDEX pending_index
  ON pending
  (screen_name, updated)
");

?>
