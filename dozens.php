#!/usr/bin/php

<?php

class Config {

  const ID = 'dozen';
  const XAUTHKEY = 'cafiojafoiej610353e1bebad8';
  //Memcachedを使うか否か
  const USEMEMCACHED = false;
  //Memcachedを使う場合はMemcachedのホストとポートの設定をしてください。
  const MEMCACHEDHOST = 'localhost';
  const MEMCACHEDPORT = 11211;

  //以上で設定終了

  /*
   * 更新対象となるドメイン群を読み込んで配列の形で返す。
   * ↓こんなかんじ [0]はゾーン名、[1]はドメイン名
   * (
   * [0] => Array
   *     (
   *         [0] => hoge.jp
   *         [1] => www.hoge.jp
   *     )
   * [1] => Array
   *     (
   *         [0] => foo.jp
   *         [1] => www.foo.jp
   *     )
   * )
   */
  static function targetList() {
    $targetList = array();
    $file = file(__DIR__ . 'targetlist');
    foreach ($file as $line) {
      $line = trim($line);
      // //で始まる行はコメントとみなす
      if (!preg_match('/^\/\//', $line)) {
        $targetList[] = preg_split('/[\s|\t]*:[\s|\t]*/', $line);
      }
    }
    return $targetList;
  }

}

class MyIPAddress {

  //現在のIPアドレスを取得
  function __construct() {
    $this->ip = trim(file_get_contents('http://ifconfig.me/ip'));
  }

  //現在のIPアドレスを返す
  function getMyIPAddress() {
    return $this->ip;
  }

  //IPアドレスが変動している場合はtrue、そうでない場合はfalseを返す
  function changedMyIPAddress() {
    if (Config::USEMEMCACHED) {
      $this->mem = new Memcache();
      $this->mem->connect(Config::MEMCACHEDHOST, Config::MEMCACHEDPORT);
      if ($this->ip == $this->mem->get('dozens_MyIPAddress')) {
        return false;
      }
      return true;
    }
    if (file_exists(__DIR__ . 'myipaddress')) {
      $file = file(__DIR__ . 'myipaddress');
      if (trim($file[0]) == $this->ip) {
        return false;
      }
    }
    return true;
  }

  //次回実行時にIPアドレスの変動をチェックするため、IPアドレスを保存する。
  function setChanged() {
    if (Config::USEMEMCACHED) {
      $this->mem->set('dozens_MyIPAddress', $this->ip, 0, 3600);
    } else {
      $fp = fopen(__DIR__ . 'myipaddress', 'w');
      fwrite($fp, $this->ip);
      fclose($fp);
    }
  }

}

class AuthRequest {

  private $recordList;

  //XAUTHトークンを取得
  function __construct() {
    $this->curl = new Curl();
    $this->curl->create('authentication');
    $this->curl->setOptions('http://dozens.jp/api/authorize.json', '');
    $this->auth_token = $this->curl->result()->auth_token;
  }

  //レコードリストを取得
  function getRecordList($zoneName) {
    if (!isset($this->recordList[$zoneName])) {
      $this->curl->create('getrecordlist');
      $this->curl->setOptions('http://dozens.jp/api/record/' . $zoneName . '.json', array('auth_token' => $this->auth_token));
      $this->recordList[$zoneName] = $this->curl->result()->record;
    }
    return $this->recordList[$zoneName];
  }

  //レコードを更新
  function updateRecord($recordID, $options) {
    $this->curl->create('updaterecord');
    $this->curl->setOptions('http://dozens.jp/api/record/update/' . $recordID . '.json', array('auth_token' => $this->auth_token, 'json' => json_encode($options)));
    $result = $this->curl->result();
    return $result;
  }

}

class Curl {

  public function create($type) {
    $this->ch = curl_init();
    $this->type = $type;
  }

  public function setOptions($url, $options) {
    $curlOptions = array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => 1
    );
    if ($this->type == 'authentication') {
      $curlOptions += array(
          CURLOPT_HTTPHEADER => array(
              'X-Auth-User: ' . Config::ID,
              'X-Auth-Key: ' . Config::XAUTHKEY
          )
      );
    } else {
      $curlOptions[CURLOPT_HTTPHEADER] = array(
          'X-Auth-Token: ' . $options['auth_token'],
          'Content-Type: application/json'
      );
      //レコードの更新の時はPOSTにする
      if ($this->type == 'updaterecord') {
        $curlOptions += array(
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $options['json']
        );
      }
    }
    curl_setopt_array($this->ch, $curlOptions);
  }

  //cURLの実行結果を返す
  public function result() {
    $result = json_decode(curl_exec($this->ch));
    curl_close($this->ch);
    return $result;
  }

}

class Dozens {

  function __construct() {
    $this->auth = new AuthRequest();
  }

  //レコードの更新に必要なレコードのID, prio, ttlを返す。
  function getRecordListofTarget($targetList) {
    $recordList = array();
    $updateList = array();
    foreach ($targetList as $target) {
      $recordList = $this->auth->getRecordList($target[0]);
      foreach ($recordList as $record) {
        if ($record->name == $target[1]) {
          $updateList[] = array(
              'ID' => $record->id,
              'prio' => $record->prio,
              'ttl' => $record->ttl
          );
          break;
        }
      }
    }
    return $updateList;
  }

  //レコードを更新する
  function updateRecords($updateList) {
    foreach ($updateList as $line) {
      $this->auth->updateRecord($line['ID'], array(
          'prio' => $line['prio'],
          'content' => $this->ip,
          'ttl' => $line['ttl']
      ));
    }
  }

  //現在のIPアドレスをセットする
  function setMyIPAddress($ip) {
    $this->ip = $ip;
  }

}

$ip = new MyIPAddress();
//IPアドレスが変動してる時だけAPIにアクセスする
if ($ip->changedMyIPAddress()) {
  $dozens = new Dozens();
  $dozens->setMyIPAddress($ip->getMyIPAddress());
  $updateList = $dozens->getRecordListofTarget(Config::targetList());
  $dozens->updateRecords($updateList);
  $ip->setChanged();
}
