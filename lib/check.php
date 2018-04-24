<?php
  function getHost($Address) {
    $parseUrl = parse_url(trim($Address)); 
    $host = trim(empty($parseUrl['host']) ? array_shift(explode('/', empty($parseUrl['path']) ? '' : $parseUrl['path'], 2)) : $parseUrl['host']); 
    return idn_to_ascii($host);
  }

  function mergeDecisions($list) {
    $merged = array();
    $map = array();
    foreach ($list as $i => $row) {
      $id = $row['gos_organ'] . ';' . $row['postanovlenie'] . ';' . $row['date'];
      if (!empty($map[$id])) {
        $group = $map[$id];
      } else {
        $group = array(
          'gos_organ' => $row['gos_organ'],
          'postanovlenie' => $row['postanovlenie'],
          'date' => $row['date'],
          'ips' => array(),
          'links' => array(),
          'pages' => array(),
        );
      }

      if ($row['ip'] && !in_array($row['ip'], $group['ips'])) {
        $group['ips'][] = $row['ip'];
      }
      if ($row['link'] && !in_array($row['link'], $group['links'])) {
        $group['links'][] = $row['link'];
      }
      if ($row['page'] && !in_array($row['page'], $group['pages'])) {
        $group['pages'][] = $row['page'];
      }
      $map[$id] = $group;
    }
    foreach ($map as $i => $group) {
      $merged[] = $group;
    }
    return $merged;
  }
 
  function checkHost($query) {
    global $db, $db_host, $db_user, $db_pass, $db_name, $equiv, $hardcoded;

    $host = strtolower($query);
    if (empty($host)) {
      exit();
    }
    $exact = $query;

    $isIP6 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
    if ($isIP6 !== false) {
      return array('error' => 1, 'error_desc' => 'IPv6 addresses are unsupported');
    }

    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $db->query("SET CHARACTER SET 'utf8mb4'");
    $db->query("SET collation_connection = 'utf8mb4_unicode_ci'");
    $db->query("SET NAMES 'utf8mb4_unicode_ci'");

    $response = array(
      'blocked' => array(),
      'ips' => array()
    );
    $blocks = array();

    $host = getHost($host);
    $isIP4 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    if ($isIP4 !== false) {
      $ips = array($host);
      $hname = gethostbyaddr($host);
      if ($hname != $host) {
        $response['host'] = array(
          'value' => $hname,
          'blocked' => array(),
        );
      }

      $response['url'] = array(
        'value' => 'http://' . $hname,
        'blocked' => array(),
      );
    } else {
      //print_r($url);
      //$host = parse_url($host, PHP_URL_HOST);

      $response['host'] = array(
        'value' => $host,
        'blocked' => array(),
      );

      if (filter_var($exact, FILTER_VALIDATE_URL) === false) {
        $exact = 'http://' . $exact;
        if (filter_var($exact, FILTER_VALIDATE_URL) === false) {
          $exact = 'http://' . $host . '/';
        }
      } else {
        $exact_scheme = parse_url($exact, PHP_URL_SCHEME);
        if (!$exact_scheme) {
          $exact = 'http://' . $exact;
        }
      }
      $response['url'] = array(
        'value' => $exact,
        'blocked' => array(),
      );

      $result = $db->query("SELECT * FROM blocked WHERE link = '" . $db->escape_string($host) . "' OR page = '" . $db->escape_string($exact) . "'");
      while ($row = $result->fetch_assoc()) {
        if ($row['link'] == $host) {
          $response['host']['blocked'][] = $row;
        }
        if ($row['page'] == $exact) {
          $response['url']['blocked'][] = $row;
        }
        //$response['blocked'][] = $row;
        $blocks[] = $row;
      }

      $ips = array();

      $norm = $host;
      if (!empty($equiv[$norm])) {
        $norm = $equiv[$norm];
      }
      if (!empty($hardcoded[$norm])) {
        $ips = $hardcoded[$norm];
      }

      if (strpos($host, '.') !== false) {
        for ($i = 0; $i < 4; $i++) {
          $res = gethostbynamel($host);
          if ($res === false || !count($res)) {
            break;
          } else {
            foreach ($res as $j => $ip) {
              if (!in_array($ip, $ips)) {
                $ips[] = $ip;
              }
            }
          }
        }
      }
      natsort($ips);
    } 

    $or = array();
    foreach ($ips as $i => $ip) {
      $ipInt = ip2long($ip);
      $or[] = "(ip_first <= $ipInt AND ip_last >= $ipInt)";
    }

    $ranges = array();
    if (count($or)) {
      //$response['query'] = "SELECT * FROM blocked WHERE " . implode(" OR ", $or);
      $result = $db->query("SELECT * FROM blocked WHERE " . implode(" OR ", $or));
      while ($row = $result->fetch_assoc()) {
        $ranges[] = $row;
        $blocks[] = $row;
      }
    }

    if (!empty($response['url'])) {
      $response['url']['blocked'] = mergeDecisions($response['url']['blocked']);
    }
    if (!empty($response['host'])) {
      $response['host']['blocked'] = mergeDecisions($response['host']['blocked']);
    }

    foreach ($ips as $i => $ip) {
      $ipInfo = array(
        'value' => $ip,
        'blocked' => array(),
      );

      $ipInt = ip2long($ip);
      foreach ($ranges as $j => $range) {
        if (intval($range['ip_first']) <= $ipInt && intval($range['ip_last']) >= $ipInt) {
          $ipInfo['blocked'][] = $range;
        }
      }
      $ipInfo['blocked'] = mergeDecisions($ipInfo['blocked']);
      $response['ips'][] = $ipInfo;
    }

    insertInto('checks', array(
      'query' => $query,
      'url' => $exact,
      'host' => $host,
      'ips' => implode(',', $ips),
      'is_blocked' => (count($blocks) > 0) ? 1 : 0,
      'blocks' => json_encode($blocks),
      'checked_at' => time(),
    ));

    return $response;
  }