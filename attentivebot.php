<?php
/*
AttentiveBot
Copyright (C) 2014 Dan Phiffer

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
*/

class AttentiveBot {
  
  var $twitter;
  var $screen_name = SCREEN_NAME;
  var $done = false;
  
  function __construct($twitter, $db) {
    $this->twitter = $twitter;
    $this->db = $db;
  }
  
  function __destruct() {
    if (!empty($this->fh)) {
      fclose($this->fh);
    }
  }
  
  function main() {
    $followers = $this->get_followers();
    $created_threshold = date('Y-m-d H:i:s', time() - (60 * 60 * 24));
    $this->users_already_tweeted = $this->db->get_column("
      SELECT screen_name
      FROM tweets
      WHERE created > ?
    ", array($created_threshold));
    $followers = array_filter($followers, array($this, 'filter_follower'));
    $this->followers = array();
    foreach ($followers as $user) {
      $this->followers[$user->screen_name] = $user;
      $this->tweet($user->screen_name);
      if (!empty($this->done)) {
        break;
      }
    }
  }
  
  function log($message, $reset_timestamp = null) {
    if (empty($this->fh)) {
      $this->fh = fopen('data/attentivebot.log', 'a');
    }
    $reset = '';
    $now = time();
    if (!empty($reset_timestamp) &&
        $reset_timestamp > $now) {
      $reset = ' reset reset-' . ($reset_timestamp - $now);
    }
    if (!is_scalar($message)) {
      $message = print_r($message, true);
    }
    echo "<div class=\"message$reset\">";
    echo "$message";
    echo "</div>\n";
    $timestamp = date('Y-m-d H:i:s');
    fwrite($this->fh, "[$timestamp] $message\n");
  }
  
  function tweet($screen_name) {
    $friends = $this->get_friends($screen_name);
    if (empty($friends)) {
      return false;
    }
    $this->tweets_already_suggested = $this->db->get_column("
      SELECT tweet_id
      FROM tweets
      WHERE screen_name = ?
    ", array($screen_name));
    $friends = array_filter($friends, array($this, 'filter_friend'));
    $friends = array_values($friends);
    array_map(array($this, 'set_score'), $friends);
    usort($friends, array($this, 'sort'));
    if (!empty($friends)) {
      $friend = $friends[0];
      $tweet = "@$screen_name: https://twitter.com/{$friend->screen_name}/statuses/{$friend->status->id_str}";
      $now = date('Y-m-d H:i:s');
      $this->db->query("
        INSERT INTO tweets
        (screen_name, tweet_id, created)
        VALUES (?, ?, ?)
      ", array($screen_name, $friend->status->id_str, $now));
      $this->log("tweet: $tweet");
      $result = $this->twitter->post("statuses/update.json", array(
        'status' => $tweet
      ));
    }
    return true;
  }
  
  function has_remaining($endpoint) {
    $remaining = $this->get_remaining($endpoint);
    $remaining_var = $this->get_remaining_var($endpoint);
    $resets_var = $this->get_resets_var($endpoint);
    if ($remaining == 0) {
      $reset_time = date('Y-m-d H:i:s', $this->$resets_var);
      $this->log("has_remaining($endpoint) resets $reset_time", $this->$resets_var);
      return false;
    } else {
      $this->$remaining_var--;
      return true;
    }
  }
  
  function get_followers() {
    $endpoint = 'followers/list.json';
    $remaining = $this->get_remaining($endpoint);
    $log_message = "get_followers({$this->screen_name}) $remaining calls remain";
    $followers = array();
    $cursor = -1;
    while ($this->has_remaining($endpoint)) {
      $result = $this->twitter->get($endpoint, array(
        'screen_name' => $this->screen_name,
        'count' => 200,
        'cursor' => $cursor
      ));
      if (!empty($result->users)) {
        $followers = array_merge($followers, $result->users);
      }
      if (empty($result->next_cursor_str)) {
        break;
      } else {
        $cursor = $result->next_cursor_str;
      }
    }
    if (!empty($followers)) {
      $log_message .= ", " . count($followers) . " followers found";
    }
    $this->log($log_message);
    //$followers = array_reverse($followers);
    return $followers;
  }
  
  function get_friends($screen_name) {
    if ($this->is_pending($screen_name)) {
      return false;
    }
    if ($this->should_check_cache()) {
      $friends = $this->get_cached_friends($screen_name);
      if (!empty($friends)) {
        return $friends;
      }
    }
    $endpoint = 'friends/list.json';
    $remaining = $this->get_remaining($endpoint);
    $log_message = "get_friends({$screen_name}) $remaining calls remain";
    $friends = array();
    $cursor = -1;
    while ($this->has_remaining($endpoint)) {
      $result = $this->twitter->get($endpoint, array(
        'screen_name' => $screen_name,
        'count' => 200,
        'cursor' => $cursor
      ));
      if (!empty($result->users)) {
        $friends = array_merge($friends, $result->users);
      } else if (!empty($result->error)) {
        if ($result->error == 'Not authorized.') {
          $this->follow_user($screen_name);
        } else {
          $this->log($result->error);
        }
      }
      if (empty($result->next_cursor_str)) {
        break;
      } else {
        $cursor = $result->next_cursor_str;
      }
      // Be more economical with API limits
      if (count($friends) > 399) {
        break;
      }
    }
    if (!empty($friends)) {
      $log_message .= ", " . count($friends) . " friends found";
      $this->set_cached_friends($screen_name, $friends);
    }
    $this->log($log_message);
    if ($remaining == 0) {
      $this->done = true;
    }
    return $friends;
  }
  
  function should_check_cache() {
    $chance = rand(1, 3);
    return $chance > 1;
  }
  
  function is_pending($screen_name) {
    $expires = date('Y-m-d H:i:s', time() - (60 * 60 * 4));
    $this->db->query("
      DELETE FROM pending
      WHERE screen_name = ?
        AND updated < ?
    ", array($screen_name, $expires));
    $pending = $this->db->get_row("
      SELECT screen_name
      FROM pending
      WHERE screen_name = ?
    ", array($screen_name));
    return !empty($pending);
  }
  
  function follow_user($screen_name) {
    $this->log("follow_user($screen_name)");
    if (!isset($this->pending)) {
      $this->pending = $this->twitter->get('friendships/outgoing.json', array(
        'stringify_ids' => true
      ));
    }
    $user = $this->followers[$screen_name];
    if (in_array($user->id_str, $this->pending->ids)) {
      $this->log('pending');
    } else {
      $this->log('follow');
      $this->twitter->post('friendships/create.json', array(
        'screen_name' => $screen_name
      ));
      $tweet = "@$screen_name hello, because your account is protected I need to follow you in order to look for tweets by the people you follow. Thank you!";
      $this->twitter->post("statuses/update.json", array(
        'status' => $tweet
      ));
    }
    $now = date('Y-m-d H:i:s');
    $this->db->query("
      INSERT INTO pending
      (screen_name, updated)
      VALUES (?, ?)
    ", array($screen_name, $now));
  }
  
  function get_cached_friends($screen_name) {
    $expires = time() - (60 * 60 * 24 * 7);
    $cached = $this->db->get_row("
      SELECT following
      FROM following
      WHERE screen_name = ?
        AND updated > ?
    ", array($screen_name, $expires));
    if (!empty($cached)) {
      return json_decode($cached->following);
    }
    return null;
  }
  
  function set_cached_friends($screen_name, $friends) {
    $json = json_encode($friends);
    $now = date('Y-m-d H:i:s');
    $this->db->query("
      DELETE FROM following
      WHERE screen_name = ?
    ", array($screen_name));
    $this->db->query("
      INSERT INTO following
      (screen_name, following, updated)
      VALUES (?, ?, ?)
    ", array($screen_name, $json, $now));
  }
  
  function get_remaining($endpoint) {
    if (preg_match('#^/?([^/]+)/#', $endpoint, $matches)) {
      $resource_family = $matches[1];
    } else {
      return null;
    }
    $endpoint = $this->normalize_endpoint($endpoint);
    $remaining_var = $this->get_remaining_var($endpoint);
    $resets_var = $this->get_resets_var($endpoint);
    if (!isset($this->$remaining_var)) {
      if (empty($this->rate_limits)) {
        $this->load_rate_limits();
      }
      $stats = $this->rate_limits
               ->resources
               ->$resource_family
               ->$endpoint;
      $this->$remaining_var = $stats->remaining;
      $this->$resets_var = $stats->reset;
    }
    return $this->$remaining_var;
  }
  
  function load_rate_limits() {
    $this->rate_limits = $this->twitter->get('application/rate_limit_status.json', array(
      'resources' => 'application,friends,followers'
    ));
  }
  
  function get_remaining_var($endpoint) {
    $endpoint = $this->normalize_endpoint($endpoint);
    return "remaining" . str_replace('/', '_', $endpoint);
  }
  
  function get_resets_var($endpoint) {
    $endpoint = $this->normalize_endpoint($endpoint);
    return "resets" . str_replace('/', '_', $endpoint);
  }
  
  function normalize_endpoint($endpoint) {
    if (substr($endpoint, 0, 1) != '/') {
      $endpoint = "/$endpoint";
    }
    $endpoint = str_replace('.json', '', $endpoint);
    return $endpoint;
  }
  
  function filter_follower($user) {
    $already_tweeted = in_array($user->screen_name, $this->users_already_tweeted);
    return !$already_tweeted;
  }
  
  function filter_friend($user) {
    if (empty($user->status)) {
      return false;
    }
    if (in_array($user->status->id_str, $this->tweets_already_suggested)) {
      return false;
    }
    $this->set_popularity($user);
    $text = trim($user->status->text);
    $is_reply = mb_substr($text, 0, 1, 'UTF-8') === '@';
    $is_retweet = mb_substr($text, 0, 3, 'UTF-8') === 'RT ';
    $is_quote = preg_match("#^(“|\")@#", $text);
    $too_old = time() - strtotime($user->status->created_at) > (60 * 60 * 24 * 7);
    $too_popular = $user->popularity > 50;
    return !$is_reply &&
           !$is_retweet &&
           !$is_quote &&
           !$too_old &&
           !$too_popular;
  }
  
  function sort($a, $b) {
    if ($a->score == $b->score) {
      return 0;
    } else {
      return ($a->score < $b->score) ? 1 : -1;
    }
  }
  
  function set_score($user) {
    $lifetime = (time() - strtotime($user->created_at)) / (60 * 60 * 24);
    $user->quietness = $lifetime / $user->statuses_count;
    $popularity = $user->popularity;
    if ($popularity > 9) {
      $popularity = 10;
    }
    $user->score = $user->quietness * $popularity;
  }
  
  function set_popularity($user) {
    $user->popularity = $user->status->retweet_count +
                        $user->status->favorite_count;
  }
  
}

?>
