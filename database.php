<?php

class Database {
  
  function __construct($path, $setup_path = null) {
    if (!file_exists(dirname($path))) {
      mkdir(dirname($path), 0700, true);
    }
    if (!file_exists($path)) {
      $this->pdo = new PDO("sqlite:$path");
      if (!empty($setup_path)) {
        $db = $this;
        include $setup_path;
      }
    } else {
      $this->pdo = new PDO("sqlite:$path");
    }
  }
  
  function query($sql, $params = null, $fetch_style = null) {
    if (empty($params)) {
      $params = array();
    }
    if ($fetch_style === null) {
      $fetch_style = PDO::FETCH_OBJ;
    }
    $query = $this->pdo->prepare($sql);
    if ($query) {
      $query->execute($params);
      if ($fetch_style === false) {
        return $query;
      }
      return $query->fetchAll($fetch_style);
    } else {
      $error_info = $this->pdo->errorInfo();
      echo "{$error_info[0]} {$error_info[1]} {$error_info[2]} ($sql)";
    }
  }
  
  function find($table, $params) {
    $objects = array();
    $where_clause = $this->parse_find_params($params);
    return $this->query("
      SELECT *
      FROM $table
      $where_clause
    ");
  }
  
  function get_row($sql, $params = null) {
    $results = $this->query($sql, $params);
    if (!empty($results)) {
      return $results[0];
    } else {
      return null;
    }
  }
  
  function get_column($sql, $params = null) {
    $results = $this->query($sql, $params, PDO::FETCH_COLUMN);
    if (!empty($results)) {
      return $results;
    } else {
      return array();
    }
  }
  
  function get_value($sql, $params = null) {
    $results = $this->query($sql, $params, PDO::FETCH_NUM);
    if (!empty($results)) {
      return $results[0][0];
    } else {
      return null;
    }
  }
  
  function prepare($sql) {
    return $this->pdo->prepare($sql);
  }
  
  function begin_transaction() {
    $this->pdo->beginTransaction();
  }
  
  function commit() {
    $this->pdo->commit();
  }
  
  function roll_back() {
    $this->pdo->rollBack();
  }
  
  function select($sql, $params = null, $fetch_style = null) {
    return $this->query($sql, $params, $fetch_style);
  }
  
  function insert($table, $values) {
    $columns = array();
    $placeholders = array();
    foreach ($values as $key => $value) {
      $columns[] = $key;
      $values[":$key"] = $value;
      $placeholders[] = ":$key";
    }
    $columns = implode(', ', $columns);
    $placeholders = implode(', ', $placeholders);
    $query = $this->query("
      INSERT INTO $table
      ($columns)
      VALUES ($placeholders);
    ", $values, false);
    if ($query->rowCount() == 1) {
      return $this->pdo->lastInsertId();
    } else {
      return false;
    }
  }
  
  function update($table, $id, $values) {
    list($id_column, $id_value) = $this->parse_id($id);
    $assignments = array();
    foreach ($values as $key => $value) {
      $assignments[] = "$key = :$key";
      $values[":$key"] = $value;
    }
    $assignments = implode(', ', $assignments);
    $this->query("
      UPDATE $table
      SET $assignments
      WHERE $id_column = $id_value;
    ", $values);
  }
  
  function delete($table, $id) {
    list($id_column, $id_value) = $this->parse_id($id);
    $this->query("
      DELETE FROM $table
      WHERE $id_column = $id_value
    ");
  }
  
  function parse_id($id) {
    // $id can either be numeric (e.g., 47) or an associative array
    // (e.g., [tweet_id => 47])
    if (is_array($id)) {
      foreach ($id as $id_column => $id_value) {
        // Just fetching the first row
        $id_value = $this->pdo->quote($id_value);
        break;
      }
    } else {
      $id_column = 'id';
      $id_value = $this->pdo->quote($id);
    }
    return array($id_column, $id_value);
  }
  
  function parse_find_params($params) {
    $values = array();
    if (is_scalar($params)) {
      $value = $this->pdo->quote($params);
      $where_clause = "WHERE id = $value";
    } else if (is_array($params) && isset($params[0])) {
      $id_values = array_map(array($this->pdo, 'quote'), $params);
      $id_values = implode(', ', $id_values);
      $where_clause = "WHERE id IN ($id_values)";
    } else {
      foreach ($params as $column => $value) {
        if (empty($where_clause)) {
          $where_clause = "WHERE $column";
        } else {
          $where_clause .= " AND $column";
        }
        if (is_scalar($value)) {
          $value = $this->pdo->quote($value);
          $where_clause .= " = $value";
        } else {
          $id_values = array_map(array($this->pdo, 'quote'), $value);
          $id_values = implode(', ', $id_values);
          $where_clause .= " IN ($id_values)";
        }
      }
    }
    return $where_clause;
  }
  
}

?>
