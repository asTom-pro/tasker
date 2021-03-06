<?php
ini_set('display_errors', "On");
ini_set('error_log', 'php.log');
// <!-- セッション関係 -->
session_save_path('/var/tmp');
ini_set('session.gc_maxlifetime', 60 * 60 * 24);
ini_set('session.cookie_lifetime', 60 * 60 * 24);

ini_set('upload_max_filesize', '1000000');

session_start();
session_regenerate_id();

// 定義
$err_msg = array();
define('MSG01', '入力してください。');
define('MSG02', '正しいメールアドレスを入力してください。');
define('MSG03', '文字以内にしてください。');
define('MSG04', '文字以上にしてください。');
define('MSG05', 'このメールアドレスはすでに登録されています。');
define('MSG06', 'パスワードとパスワード（再入力）が一致していません。');
define('MSG07', 'メールアドレスかパスワードが間違っています。');
define('MSG08', '1MB以下にしてください。');
define('ERR_QUERUY', 'データベースエラーが起こり保存できませんでした。時間を開けて再度行なってください。');
define('ERR_MAILCONFIRM', 'データーベースエラーが起こりメールを送信できませんでした。時間をあけて再度行ってください。');
define('ERR_MAIL_NOT_MATCH', 'データーベースエラーが起こりメールを送信できませんでした。時間をあけて再度行ってください。');




$debug_flg = false;
$debug_flg = true;



if ($debug_flg) {
  function debug($str)
  {
    error_log(print_r($str, true));
  }
} else {
  function debug($str)
  {
    return;
  }
}
function debugLogStart()
{
  debug('----------------------------------------------------------');
  debug('-デバッグスタート-');
  debug('----------------------------------------------------------');
  if (!empty($_SESSION)) {
    debug('セッションファイルがあります。');
    debug('セッションファイル：' . print_r($_SESSION, true));
  } else {
    debug('セッションファイルなし。');
  }
}

function sanitize($str)
{
  return htmlspecialchars($str, ENT_QUOTES);
}

function validRequired($str, $key)
{
  if (empty($str)) {
    global $err_msg;
    $err_msg[$key] = MSG01;
  }
}
function validEmail($email, $key)
{
  if (!preg_match("/^[a-zA-Z0-9.!#$%&'*+\/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)*$/", $email)) {
    global $err_msg;
    $err_msg[$key] = MSG02;
  }
}

function validLenMax($str, $key, $max)
{
  if (mb_strlen($str) > $max) {
    global $err_msg;
    $err_msg[$key] = $max . MSG03;
  }
}
function validLenMin($str, $key, $min = 6)
{
  if (mb_strlen($str) < $min) {
    global $err_msg;
    $err_msg[$key] = $min . MSG04;
  }
}

function validEmailDup($email, $key)
{
  try {
    $dbh = dbConnect();
    $sql = 'SELECT * FROM user WHERE email = :email AND delete_flg = 0';
    $data = array(':email' => $email);
    $stmt = queryPost($dbh, $sql, $data);
    $rst = $stmt->fetch(PDO::FETCH_ASSOC);
    debug($rst);
    if ($rst) {
      global $err_msg;
      $err_msg[$key] = MSG05;
      return false;
    }
  } catch (Exception $e) {
    debug('データベースエラー：' . $e->getMessage());
  }
}
function validMatched($str1, $str2, $key)
{
  if ($str1 != $str2) {
    global $err_msg;
    $err_msg[$key] = MSG06;
  }
}
function dedupTag($str = array())
{
  try {
    $dbh = dbConnect();
    $sql = 'SELECT room_tag_name FROM room_tag';
    $data = array();
    $stmt = queryPost($dbh, $sql, $data);
    $stmt = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // 多次元配列を一元配列にする
    foreach ($stmt as $key => $val) {
      foreach ($val as $key => $val) {
        $array[] = $val;
      }
    }
    $diff = array_diff($str, $array);
    return $diff;
  } catch (Exception $e) {
    debug('データベースエラー：' . $e->getMessage());
  }
}

function dbConnect()
{
  debug('データベース接続します。');
  try {
    $dsn = 'mysql:host='.getenv('HOST').';dbname='.getenv('DB_NAME').';charset=utf8';
    $user = getenv('DB_USER');
    $password = getenv('DB_PASSWORD');
    $options = array(
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    );
    $pdo = new PDO($dsn, $user, $password, $options);
    return $pdo;
  } catch (Exception $e) {
    debug('データベースエラー：' . $e->getMessage());
  }
}
function queryPost($dbh, $sql, $data = array())
{
  try {
    $pdo = $dbh->prepare($sql);
    $rst = $pdo->execute($data);
    if ($rst) {
      debug('データベース接続成功');
      return $pdo;
    } else {
      debug('データベース接続失敗');
      debug('失敗したSQL' . $sql);
      return false;
    }
  } catch (Exception $e) {
    debug('データーベースエラー：' . $e->getMessage());
  }
}

function getMyOpenRoom($user_id)
{
  try {
    debug('１部屋情報を取得・トライ');
    $dbh = dbConnect();
    $sql = 'SELECT  * FROM room WHERE user_id = :u_id AND create_date = (SELECT MAX(create_date) FROM room WHERE user_id = :u_id)';
    $data = array(':u_id' => $user_id);
    $stmt = queryPost($dbh, $sql, $data);
    $rst =  $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($rst)) {
      $room_end_flg = false; //部屋稼働中
      $formed_date = strtotime($rst['create_date']);
      $limit_time_sec = (idate('U', $formed_date) + $rst['room_time_limit']) - time();
      if ($limit_time_sec < 0) {
        $room_end_flg = true; //部屋終了
        $limit_time_sec = 0;
      }
      if (!$room_end_flg) {
        debug('ログインユーザーの開いてる部屋情報を取得・成功（空いてる部屋がありました。）');
        debug('部屋情報：' . print_r($rst, true));
        return $rst;
      } else {
        debug('ログインユーザーの開いてる部屋情報を取得・失敗（空いてる部屋がありません。）');
        return 0;
      }
    }
  } catch (Exception $e) {
    debug('データーベースエラー：' . $e->getMessage());
  }
}

function getRoomOne($room_id)
{
  debug('１部屋情報を取得します。');
  try {
    debug('１部屋情報を取得・トライ');
    $dbh = dbConnect();
    $sql = 'SELECT  * FROM room WHERE room_id = :id';
    $data = array(':id' => $room_id);
    $stmt = queryPost($dbh, $sql, $data);
    $rst =  $stmt->fetch(PDO::FETCH_ASSOC);
    if ($rst) {
      debug('１部屋情報を取得・成功');
      debug('部屋情報：' . print_r($rst, true));
      return $rst;
    } else {
      debug('１部屋情報を取得・失敗');
      return false;
    }
  } catch (Exception $e) {
    debug('データーベースエラー：' . $e->getMessage());
  }
}
function getUser($id)
{
  try {
    debug('ユーザー情報を取得・トライ');
    $dbh = dbConnect();
    $sql = 'SELECT * FROM user WHERE id = :id AND delete_flg = 0';
    $data = array(':id' => $id);
    $stmt = queryPost($dbh, $sql, $data);
    $rst =  $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($stmt)) {
      debug('ユーザー情報を取得・成功');
      debug('ユーザー情報：' . print_r($rst, true));
      return $rst;
    } else {
      debug('ユーザー情報を取得・失敗');
    }
  } catch (Exception $e) {
    debug('データーベースエラー：' . $e->getMessage());
  }
}

function getRoomUserNum($id)
{
  try {
    debug('ユーザー数を取得・トライ');
    $dbh = dbConnect();
    $sql = 'SELECT count(DISTINCT join_user_id) FROM join_room_user WHERE room_id = :id';
    $data = array(':id' => $id);
    $rst = queryPost($dbh, $sql, $data);
    if (!empty($rst)) {
      debug('ユーザー数を取得・成功');
      return $rst->fetchColumn();
    } else {
      debug('ユーザー数を取得・失敗');
    }
  } catch (Exception $e) {
    debug('データーベースエラー：' . $e->getMessage());
  }
}
function getRoomsInfo($currentMinNum = 1, $sort = 'DESC', $search = '', $span = 20)
{
  try {
    debug('全部屋情報を取得・トライ');
    $dbh = dbConnect();
    $sql = 'SELECT * FROM room ';
    $data = array();
    if (!empty($search)) {
      $sql .= 'WHERE room_name LIKE :search ';
      $data = array(':search' => '%' . $search . '%');
    }
    if (!empty($sort)) {
      switch ($sort) {
        case 'ASOC':
          $sql .= 'ORDER BY create_date ASOC';
          break;
        case 'DESC':
          $sql .= 'ORDER BY create_date DESC';
          break;
        default:
          $sql .= 'ORDER BY create_date DESC';
      }
    }
    debug('SQL：' . $sql);
    // クエリ実行
    $stmt = queryPost($dbh, $sql, $data);
    // レコード数を取得
    $rst['total'] = $stmt->rowCount();

    $rst['total_page'] = ceil($rst['total'] / $span);
    if (empty($rst)) {
      return false;
    }

    $sql = 'SELECT * FROM room ';
    if (!empty($search)) {
      $sql .= 'WHERE room_name LIKE :search ';
    }
    if (!empty($sort)) {
      switch ($sort) {
        case 'ASC':
          $sql .= ' ORDER BY create_date ASC';
          break;
        case 'DESC':
          $sql .= ' ORDER BY create_date DESC';
          break;
        default:
          $sql .= ' ORDER BY create_date DESC';
      }
    }
    if (!empty($span)) {
      $sql .= ' LIMIT ' . $span . ' OFFSET ' . ($currentMinNum - 1);
    }
    $data = array(':span' => $span, ':search' => '%' . $search . '%');
    $stmt = queryPost($dbh, $sql, $data);
    debug('ここまでは来てる');
    debug($stmt);
    debug('SQLこれ' . print_r($sql, true));
    if ($stmt) {
      $rst['data'] = $stmt->fetchAll();
      debug('全部屋情報：' . print_r($rst, true));
      return $rst;
    } elseif ($stmt == 0) {
      debug('取得した部屋数は0です');
      return false;
    }
  } catch (Exception $e) {
    debug('データベースエラー：' . $e->getMessage());
  }
}

function getRoomsInfoOneUser($u_id = '')
{
  try {
    debug('全部屋情報を取得・トライ');
    $dbh = dbConnect();
    $sql = 'SELECT * FROM room WHERE user_id = :u_id ORDER BY create_date DESC';
    $data = array(':u_id' => $u_id);
    $stmt = queryPost($dbh, $sql, $data);
    if ($stmt) {
      $rst = $stmt->fetchAll();
      debug('１ユーザーの全部屋情報：' . print_r($rst, true));
      return $rst;
    } elseif ($stmt == 0) {
      debug('取得した１ユーザーの部屋数は0です');
    }
  } catch (Exception $e) {
    debug('データベースエラー：' . $e->getMessage());
  }
}


function getRoomsInfoRelatedTag($r_id = array())
{
  $rst = array();
  try {
    $dbh = dbConnect();
    $sql = 'SELECT * FROM room WHERE room_id = :r_id';
    $r_id = $r_id[0];
    debug('変化前$r_id' . print_r($r_id, true));
    rsort($r_id);
    debug('変化後$r_id' . print_r($r_id, true));
    foreach ($r_id as $key => $val) {
      $data = array(':r_id' => $val);
      $stmt = queryPost($dbh, $sql, $data);
      $rst[] = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    return $rst;
  } catch (Exception $e) {
    debug('データベースエラー：' . $e->getMessage());
  }
}

function getRoomsNum($u_id =  '')
{
  try {
    debug('指定した部屋数を取得・トライ');
    $dbh = dbConnect();
    $sql = 'SELECT count(*) FROM room';
    $data = array();
    if (!empty($u_id)) {
      $sql .= ' WHERE user_id = :u_id';
      $data = array(':u_id' => $u_id);
    }
    $rst =  queryPost($dbh, $sql, $data);
    if ($rst) {
      debug('指定した部屋数を取得・成功');
      return $rst->fetchColumn();
    }
  } catch (Exception $e) {
    debug('データベースエラー：' . $e->getMessage());
  }
}
function getJoinedRoomId($u_id)
{
  debug('参加したすべての部屋IDを取得・トライ');
  $dbh = dbConnect();
  $sql = 'SELECT DISTINCT room_id FROM join_room_user WHERE join_user_id = :u_id';
  $data = array(':u_id' => $u_id);
  $stmt = queryPost($dbh, $sql, $data);
  $rst = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if ($stmt) {
    debug('取得した参加したすべての部屋のID' . print_r($rst, true));
    return $rst;
  }
}

function getRoomTag($r_id)
{
  debug('部屋IDから部屋タグ名取得・トライ');
  try {
    $dbh = dbConnect();
    $sql = 'SELECT room_tag_id FROM rooms_room_tags WHERE room_id = :r_id';
    $data = array(':r_id' => $r_id);
    $stmt = queryPost($dbh, $sql, $data);
    $rst = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $array = array();
    foreach ($rst as $key => $val) {
      foreach ($val as $key => $val) {
        $array[] = $val;
      }
    }
    if ($stmt) {
      debug('部屋ID' . $r_id . 'のタグ取得' . print_r($array, true));
      $tags = array();
      foreach ($array as $key => $val) {
        if (!empty($val)) {
          $sql = 'SELECT room_tag_name FROM room_tag WHERE room_tag_id = :room_tag_id';
          $data = array(':room_tag_id' => $val);
          $stmt = queryPost($dbh, $sql, $data);
          $rst = $stmt->fetch(PDO::FETCH_ASSOC);
          if (!empty($rst['room_tag_name'])) {
            $tags[] = $rst['room_tag_name'];
          }
        }
      }
      debug('全タグネーム' . print_r($tags, true));
      return $tags;
    }
  } catch (Exception $e) {
    debug('データーベースエラー：' . $e->getMessage());
  }
}

function getTags()
{
  try {
    $dbh = dbConnect();
    $sql = 'SELECT * FROM room_tag ORDER BY room_tag_id DESC';
    $data = array();
    $stmt = queryPost($dbh, $sql, $data);
    $rst = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rst)) {
      debug('全タグ取得・成功');
      debug('全タグ' . print_r($rst, true));
      return $rst;
    } else {
      debug('全タグ取得・失敗');
      return false;
    }
  } catch (Exception $e) {
    debug('データーベースエラー：' . $e->getMessage());
  }
}

function getTagId($tag_name)
{
  try {
    $dbh = dbConnect();
    $sql = 'SELECT room_tag_id FROM room_tag WHERE room_tag_name = :tag_name';
    $data = array(':tag_name' => $tag_name);
    $stmt = queryPost($dbh, $sql, $data);
    $rst = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($stmt) {
      debug('タグID取得・成功');
      debug('取得したタグID' . print_r($rst, true));
      return $rst['room_tag_id'];
    } else {
      debug('タグID取得・失敗');
    }
  } catch (Exception $e) {
    debug('データーベースエラー：' . $e->getMessage());
  }
}

function getRoomId($tag_id)
{
  try {
    $dbh = dbConnect();
    $sql = 'SELECT room_id FROM rooms_room_tags WHERE room_tag_id = :tag_id';
    $data = array(':tag_id' => $tag_id);
    $stmt = queryPost($dbh, $sql, $data);
    $rst = $stmt->fetchAll(PDO::FETCH_ASSOC);
    debug('取得した部屋ID' . print_r($rst, true));
    debug('引数のタグID' . print_r($tag_id, true));
    if ($stmt) {
      debug('部屋ID取得・成功');
      $room_id_array = array();
      foreach ($rst as $key => $val) {
        $room_id_array[] = $val['room_id'];
      }
      debug('取得した部屋ID' . print_r($room_id_array, true));
      return $room_id_array;
    } else {
      debug('部屋ID取得・失敗');
    }
  } catch (Exception $e) {
    debug('データーベースエラー：' . $e->getMessage());
  }
}

function getFollowingList($user_id)
{
  try {
    $dbh = dbConnect();
    $sql = 'SELECT * FROM follow WHERE user_id = :u_id';
    $data =  array(':u_id' => $user_id);
    $stmt = queryPost($dbh, $sql, $data);
    $rst = $stmt->fetchALL(PDO::FETCH_ASSOC);
    if ($stmt) {
      debug('取得したフォロー情報' . print_r($rst, true));
      return $rst;
    } else {
      debug('フォローが０でした');
    }
  } catch (Exception $e) {
    debug('データーベースエラー：' . $e->getMessage());
  }
}
function getFollowerList($user_id)
{
  try {
    $dbh = dbConnect();
    $sql = 'SELECT * FROM follow WHERE follow_id = :u_id';
    $data =  array(':u_id' => $user_id);
    $stmt = queryPost($dbh, $sql, $data);
    $rst = $stmt->fetchALL(PDO::FETCH_ASSOC);
    if ($stmt) {
      debug('取得したフォロワー情報' . print_r($rst, true));
      return $rst;
    } else {
      debug('フォロワーが０でした');
    }
  } catch (Exception $e) {
    debug('データーベースエラー：' . $e->getMessage());
  }
}

function getBoard($room_id, $update_date = 0)
{
  try {
    debug('部屋の掲示板情報を取得・トライ');
    $dbh = dbConnect();
    $sql = 'SELECT * FROM board WHERE room_id = :r_id AND create_date > :update_date ORDER BY create_date ASC';
    $data = array(':r_id' => $room_id, ':update_date' => date('Y/m/d H:i:s', $update_date));
    debug('エポックタイムを日付に' . date('Y/m/d H:i:s', $update_date));
    $stmt =  queryPost($dbh, $sql, $data);
    $rst = $stmt->fetchAll(PDO::FETCH_ASSOC);
    debug('部屋の掲示板情報' . print_r($rst, true));
    if ($rst) {
      debug('部屋の掲示板情報を取得・成功');
      return $rst;
    } else {
      debug('部屋の掲示板情報を取得・失敗');
    }
  } catch (Exception $e) {
    debug('データーベースエラー：' . $e->getMessage());
  }
}

function getLogs($uri)
{
  debug('ログを取得します');
  try {
    $dbh =  dbConnect();
    $sql = "SELECT COUNT(DISTINCT ipaddress) as cnt FROM logs WHERE uri = :uri AND update_date > CURRENT_TIMESTAMP + interval -1 minute";
    $data = array(':uri' => $uri);
    $stmt = queryPost($dbh, $sql, $data);
    $rst = $stmt->fetch();
    debug('取得したIPアドレス数です' . print_r($rst['cnt'], true));
    return $rst['cnt'];
  } catch (Exception $e) {
    debug('データーベースエラー：' . $e->getMessage());
  }
}

function getEndTask($user_id)
{
  try {
    $dbh = dbConnect();
    $sql = 'SELECT * FROM ended_task WHERE user_id = :u_id ORDER BY create_date DESC ';
    $data =  array(':u_id' => $user_id);
    $stmt = queryPost($dbh, $sql, $data);
    $rst =  $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rst) {
      debug('ユーザータスク' . print_r($rst, true));
      return $rst;
    }
  } catch (Exception $e) {
    debug('データーベースエラー：' . $e->getMessage());
  }
}

function getSessionOnce($key)
{
  if (!empty($_SESSION[$key])) {
    debug('セッションを一度だけ取得します。該当セッション：'.print_r($_SESSION[$key],true));
    $data = $_SESSION[$key];
    $_SESSION[$key] = '';
    unset($_SESSION[$key]);
    return $data;
  }
}

function isLogin()
{
  debug('ログイン情報を確認します。');
  if (!empty($_SESSION['login_limit'])) {
    if ($_SESSION['login_limit'] < time()) {
      debug('ログイン期間をオーバーしています。');
      return false;
    } else {
      debug('ログイン期間中です。');
      return true;
    }
  } else {
    debug('ログイン情報がありません。');
    return false;
  }
}

function isJoinMember($room_id, $join_user_id)
{
  debug('部屋参加情報を確認します。');
  $roomInfo = getRoomOne($room_id);
  $roomCreateTime = $roomInfo['create_date'];
  $roomMaxTime =  $roomInfo['room_time_limit'];
  // 部屋の作成時間から24時間以内ならtrueにしたい
  // （条件修正）部屋の"終了"時間から24時間以内ならtrueにしたい
  if ((strtotime($roomCreateTime) + $roomMaxTime + (60 * 60 * 24)) > strtotime(date("Y/m/d H:i:s"))) {
    debug('一日以内に作成された部屋です。');
    try {
      $dbh = dbConnect();
      $sql = 'SELECT count(*) FROM join_room_user WHERE room_id = :r_id AND join_user_id = :j_u_id AND :create_date > (NOW() - INTERVAL 1 DAY)';
      $data = array(':r_id' => $room_id, ':j_u_id' => $join_user_id, ':create_date' => $roomCreateTime);
      $stmt =  queryPost($dbh, $sql, $data);
      $rst = $stmt->fetch(PDO::FETCH_ASSOC);
      debug('部屋に参加した人数' . print_r($rst, true));
      if ($rst['count(*)']) {
        debug('部屋に参加しています。');
        return true;
      } else {
        debug('部屋に参加していません。');
        return false;
      }
    } catch (Exception $e) {
      debug('データベースエラー：' . print_r($e->getMessage()));
    }
  } else {
    debug('この部屋は作成して１日以上が立っています。');
    return false;
  }
}

function isGuest()
{
  if (empty($_SESSION['user_id']) || $_SESSION['user_id'] == 1) {
    return true;
  } else {
    return false;
  }
}

function uploadImg($file, $key)
{
  debug('画像をアップロード開始。');
  if(isset($file['error']) && is_int($file['error'])){
    ini_set('upload_max_filesize','1MB');
    try{
      switch ($file['error']) {
        case UPLOAD_ERR_OK:
          break;
        case UPLOAD_ERR_INI_SIZE:
          throw new RuntimeException('ファイルサイズは1MB以下にしてください。');
          break;
        case UPLOAD_ERR_PARTIAL:
          throw new RuntimeException('アップロードされたファイルは一部のみしかアップロードされていませんので、お手数ですが時間をあけて再度行ってください。');
          break;
        case UPLOAD_ERR_NO_FILE:
          throw new RuntimeException('ファイルはアップロードされませんでしたので、お手数ですが時間をあけて再度行ってください。');
          break;
        case UPLOAD_ERR_NO_TMP_DIR:
          throw new RuntimeException('テンポラリフォルダがありませんので、お手数ですが時間をあけて再度行ってください。');
          break;
        case UPLOAD_ERR_CANT_WRITE:
          throw new RuntimeException('ディスクへの書き込みに失敗しましたので、お手数ですが時間をあけて再度行ってください。');
          break;
        case UPLOAD_ERR_EXTENSION:
          throw new RuntimeException('ファイルの拡張子は、GIF、JPEG、PNGのみアップロード可能です。');
          break;
        default :
          throw new RuntimeException('その他のエラーが発生しました。');
          break;
      };    
      $mineType = array(IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG);
      $link = $file['tmp_name'];
      $type = @exif_imagetype($link);
      $type = array_search(exif_imagetype($link), $mineType);
      if (!empty($type)) {
        $to_link = '../img.task-live.com/' . sha1_file($link) . image_type_to_extension($type);
        if (move_uploaded_file($link, $to_link)) {
          debug('ファイルをディレクトリに移動させました。');
          chmod($to_link, 0644);
          return $to_link;
        }
      }
    }catch(RuntimeException $e){
      debug($e->getMessage());
      global $err_msg;
      $err_msg[$key] = $e->getMessage();
    }
  }
}

function showImg($path)
{
  if (!empty($path)) {
    return 'img/sample.img.png';
  } else {
    return $path;
  }
}

function showErrMsg(){
  global $err_msg;
  if (!empty(count($err_msg))) {
    for($i = 0;  $i < count($err_msg); $i++){
      echo '<span class="err" style="float:none" >' . sanitize($err_msg[$i]) . '</span>'; 
    }
  }
}

function setLogs($uri, $ipaddress)
{
  $dbh =  dbConnect();
  // データベースに同じipアドレスがあるなら更新。なかったら挿入
  $sql =  "SELECT COUNT(*) FROM logs WHERE uri = :uri AND ipaddress = :ipaddress";
  $data =  array(':uri' => $uri, ':ipaddress' => $ipaddress);
  $stmt = queryPost($dbh, $sql, $data);
  $rst = $stmt->fetch(PDO::FETCH_ASSOC);
  debug('setlogsのやつ' . print_r($rst, true));
  if (empty($rst['COUNT(*)'])) {
    debug('インサートします');
    $sql = "INSERT INTO logs(uri, ipaddress) VALUES (:uri, :ipaddress)";
    $rst = queryPost($dbh, $sql, $data);
  } else {
    debug('更新します');
    $sql = "UPDATE logs SET update_date = CURRENT_TIMESTAMP WHERE uri = :uri AND ipaddress = :ipaddress";
    $rst = queryPost($dbh, $sql, $data);
  }
  return $rst;
}

function sendMail($from, $to, $subject, $comment)
{
  if (!empty($to) && !empty($subject) && !empty($comment)) {
    mb_language('Japanese');
    mb_internal_encoding('UTF-8');
    $result = mb_send_mail($to, $subject, $comment, "From: " . $from);
    if ($result) {
      debug('メールを送信しました。');
    } else {
      debug('メールの送信に失敗しました。');
    }
  }
}

function pagenation($totalPageNum, $currentPageNum, $pageColNum = 5)
{
  debug('ページネーション・パラメータ'.print_r($totalPageNum,true));
  debug('ページネーション・パラメータ'.print_r($currentPageNum,true));
  debug('ページネーション・パラメータ'.print_r($pageColNum,true));
  if ((int)$currentPageNum === (int)$totalPageNum && (int)$totalPageNum > (int)$pageColNum) {
    debug('これは1');
    $minPageNum = $totalPageNum - 4;
    $maxPageNum = $totalPageNum;
  } elseif ((int)$currentPageNum === (int)($totalPageNum - 1) && (int)$totalPageNum > (int)$pageColNum) {
    debug('これは2');

    $minPageNum = $currentPageNum - 3;
    $maxPageNum = $currentPageNum + 1;
  } elseif ((int)$currentPageNum === 2 && (int)$totalPageNum > (int)$pageColNum) {
    debug('これは3');

    $minPageNum = $currentPageNum - 1;
    $maxPageNum = $currentPageNum + 3;
  } elseif ((int)$currentPageNum === 1 && (int)$totalPageNum > (int)$pageColNum) {
    debug('これは4');
    $minPageNum = $currentPageNum;
    $maxPageNum = $currentPageNum + 4;
  } elseif ((int)$totalPageNum < (int)$pageColNum) {
    debug('これは5');

    $minPageNum = 1;
    $maxPageNum = $totalPageNum;
  }else {
    debug('これは6');

    $minPageNum = $currentPageNum - 2;
    $maxPageNum = $currentPageNum + 2;
  }
  debug('最大ページ数' . print_r($maxPageNum, true));
  debug('最小ページ数' . print_r($minPageNum, true));

  // <!-- ページネーションHTML -->
  ob_start();
?>
  <div class="pagenation">
    <ul class="pagenation-list">
      <?php if((int)$currentPageNum !== 1){ ?>
      <li class="pagenation-list-item">
        <a href="index.php?p=<?php echo ($currentPageNum - 1); ?>" class="pagenation-list-item-a">&lt;</a>
      </li>
      <?php } ?>
      <?php
      for ($i = $minPageNum; $i <= $maxPageNum; $i++) {
      ?>
        <li class="pagenation-list-item">
          <a href="index.php?p=<?php echo $i; ?>"  class="pagenation-list-item-a  <?php if((int)$currentPageNum === (int)$i){echo 'is-active';} ?>"><?php echo $i; ?></a>
        </li>
      <?php
      }
      ?>
      <?php if((int)$currentPageNum !== (int)$maxPageNum){ ?>
      <li class="pagenation-list-item">
        <a href="index.php?p=<?php echo $currentPageNum + 1; ?>" class="pagenation-list-item-a">&gt;</a>
      </li>
      <?php } ?>
    </ul>
  </div>
<?php
 $str = ob_get_contents();
 return $str;
}
