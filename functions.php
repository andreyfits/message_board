<?php

function connectDb()
{
    $db = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

    if (mysqli_connect_errno()) {
        echo "Failed to connect to MySQL: " . mysqli_connect_error();
        exit();
    }

    return $db;
}

function clearStr($str): string
{
    return trim(strip_tags($str));
}

function render($path, $param = [])
{
    extract($param);

    ob_start();

    if (!include($path . ".tpl.php")) {
        exit("Template doesn't exist");
    }

    return ob_get_clean();
}

function registration($post)
{
    global $db;

    if (!$db instanceof mysqli) {
        $db = connectDb();
    }

    $login    = clearStr($post['reg_login']);
    $password = trim($post['reg_password']);
    $confPass = trim($post['reg_password_confirm']);
    $email    = clearStr($post['reg_email']);
    $name     = clearStr($post['reg_name']);

    $msg = '';

    if (empty($login)) {
        $msg .= "Введите логин <br />";
    }
    if (empty($password)) {
        $msg .= "Введите пароль <br />";
    }
    if (empty($email)) {
        $msg .= "Введите адрес почтового ящика <br />";
    }
    if (empty($name)) {
        $msg .= "Введите имя <br />";
    }

    if ($msg) {
        $_SESSION['reg']['login'] = $login;
        $_SESSION['reg']['email'] = $email;
        $_SESSION['reg']['name']  = $name;
        return $msg;
    }

    if ($confPass === $password) {
        $query = "SELECT user_id FROM " . PREF . "users WHERE login='%s'";

        $query = sprintf($query, mysqli_real_escape_string($db, $login));

        $result = mysqli_query($db, $query);

        if (mysqli_num_rows($result) > 0) {
            $_SESSION['reg']['email'] = $email;
            $_SESSION['reg']['name']  = $name;

            return "Пользователь с таким логином уже существует";
        }

        $password = md5($password);
        $hash     = md5(microtime());

        $query = "
            INSERT INTO " . PREF . "users (name, email, password, login, hash) 
            VALUES ('%s', '%s', '$password', '%s', '$hash')
        ";

        $query = sprintf(
            $query,
            mysqli_real_escape_string($db, $name),
            mysqli_real_escape_string($db, $email),
            mysqli_real_escape_string($db, $login)
        );

        $result2 = mysqli_query($db, $query);

        if (!$result2) {
            $_SESSION['reg']['login'] = $login;
            $_SESSION['reg']['email'] = $email;
            $_SESSION['reg']['name'] = $name;

            return "Ошибка при добавлении пользователя в базу данных " . mysqli_error($db);
        }

        $headers = "From: Admin <admin@mail.ru> \r\n";
        $headers .= "Content-Type: text/plain; charset=utf8";

        $subject = "registration";

        $mail_body = "Спасибо за регистрацию на сайте. Ваша ссылка для подтверждения  учетной записи: " . SITE_NAME .
            "?action=registration&hash=" . $hash;

        mail($email, $subject, $mail_body, $headers);

        return true;
    }

    $_SESSION['reg']['login'] = $login;
    $_SESSION['reg']['email'] = $email;
    $_SESSION['reg']['name']  = $name;

    return "Вы не правильно подтвердили пароль";
}

function confirm()
{
    global $db;

    if (!$db instanceof mysqli) {
        $db = connectDb();
    }

    $newHash = clearStr($_GET['hash']);

    $queryUpdate = "UPDATE ". PREF . "users SET confirm='1' WHERE hash = '%s'";
    $queryUpdate = sprintf($queryUpdate, mysqli_real_escape_string($db, $newHash));

    $result = mysqli_query($db, $queryUpdate);

    if(mysqli_affected_rows($db) === 1) {
        return true;
    }

    return "Неверный код подтверждения регистрации или учетная запись уже была активирована";
}

function login($post)
{
    global $db;

    if (!$db instanceof mysqli) {
        $db = connectDb();
    }

    if (empty($post['login']) || empty($post['password'])) {
        return "Заполните поля";
    }

    $login    = clearStr($post['login']);
    $password = md5(trim($post['password']));

    $query = "SELECT user_id, confirm FROM " . PREF . "users WHERE login = '%s' AND password = '%s'";
    $query = sprintf($query, mysqli_real_escape_string($db, $login), $password);

    $result = mysqli_query($db, $query);

    if (!$result || mysqli_num_rows($result) < 1) {
        return "Неправильный логин или пароль";
    }

    if(mysqli_fetch_assoc($result)['confirm'] == 0) {
        return "Пользователь с таким логином еще не подтвержден";
    }

    $sess = md5(microtime());

    $queryUpdate = "UPDATE " . PREF . "users SET sess='$sess'  WHERE login='%s'";
    $queryUpdate = sprintf($queryUpdate, mysqli_real_escape_string($db, $login));

    if (!mysqli_query($db, $queryUpdate)) {
        return "Ошибка авторизации пользователя";
    }

    $_SESSION['sess'] = $sess;

    if ($post['member'] === 1) {
        $time = time() + 10 * 24 * 3600;

        setcookie('login', $login, $time);
        setcookie('password', $password, $time);
    }

    return true;
}

function logout(): bool
{
    unset($_SESSION['sess']);

    setcookie('login', '', time() - 3600);
    setcookie('password', '', time() - 3600);

    return true;
}

function checkUser()
{
    global $db;

    if (!$db instanceof mysqli) {
        $db = connectDb();
    }

    if (isset($_SESSION['sess'])) {
        $sess = $_SESSION['sess'];

        $query = "SELECT user_id, name, id_role FROM " . PREF . "users WHERE sess='$sess'";

        $result = mysqli_query($db,$query);

        if(!$result || mysqli_num_rows($result) < 1) {
            return false;
        }

        return mysqli_fetch_assoc($result);
    }

    if (isset($_COOKIE['login'], $_COOKIE['password'])) {
        $login    = $_COOKIE['login'];
        $password = $_COOKIE['password'];

        $query = "
            SELECT user_id, name, id_role
            FROM " . PREF . "users
            WHERE login='$login'
            AND password='$password'
            AND confirm = '1'
        ";

        $result2 = mysqli_query($db,$query);

        if (!$result2 || mysqli_num_rows($result2) < 1) {
            return false;
        }

        $sess = md5(microtime());

        $queryUpdate = "UPDATE " . PREF . "users SET sess='$sess' WHERE login='%s'";
        $queryUpdate = sprintf($queryUpdate, mysqli_real_escape_string($db,$login));

        if (!mysqli_query($db,$queryUpdate)) {
            return "Ошибка авторизации пользователя";
        }

        $_SESSION['sess'] = $sess;

        return mysqli_fetch_assoc($result2);
    }

    return false;
}

function getPassword($email)
{
    global $db;

    if (!$db instanceof mysqli) {
        $db = connectDb();
    }

    $email = clearStr($email);

    $query = "SELECT user_id FROM " . PREF . "users WHERE email = '%s'";
    $query = sprintf($query, mysqli_real_escape_string($db, $email));

    $result = mysqli_query($db, $query);

    if (!$result) {
        return "Невозможно сгенерировать новый пароль";
    }

    if (mysqli_num_rows($result) === 1) {
        $str = "234567890qwertyuiopasdfghjklzxcvbnmQWERTYUIOPASDFGHJKLZXCVBNM";

        $pass = '';

        for ($i = 0; $i < 6; $i++) {
            $x = random_int(0, (strlen($str) - 1));

            if (($i !== 0) && $pass[strlen($str) - 1] === $str[$x]) {
                $i--;
                continue;
            }
            $pass .= $str[$x];
        }

        $md5pass = md5($pass);

        $query = "
            UPDATE " . PREF . "users 
            SET password='$md5pass' 
            WHERE user_id = '" . mysqli_fetch_assoc($result)['user_id'] . "'
        ";

        $result2 = mysqli_query($db, $query);

        if (!$result2) {
            return "Не возможно сгенерировать новый пароль";
        }

        $headers = "From: Admin <admin@mail.ru> \r\n";
        $headers .= "Content-Type: text/plain; charset=utf8";

        $subject = 'new password';
        $mail_body = "Ваш новый пароль: " . $pass;

        mail($email, $subject, $mail_body, $headers);

        return true;
    }

    return "Пользователя с таким почтовым ящиком нет";
}

function can($id, $privilegesAdm): bool
{
    $privileges = getPrivileges($id);

    if (!$privileges) {
        $privileges = [];
    }

    $arr = array_intersect($privilegesAdm, $privileges);

    return $arr === $privilegesAdm;
}

function getPrivileges($id)
{
    global $db;

    if (!$db instanceof mysqli) {
        $db = connectDb();
    }

    $query = "
        SELECT " . PREF . "priv.name AS priv 
        FROM " . PREF . "priv
        LEFT JOIN " . PREF . "role_priv
            ON " . PREF . "role_priv.id_priv = " . PREF . "priv.id
        WHERE " . PREF . "role_priv.id_role = '$id'
    ";

    $result = mysqli_query($db, $query);

    if (!$result) {
        return false;
    }

    for ($i = 0; $i < mysqli_num_rows($result); $i++) {
        $row = mysqli_fetch_array($result);

        $arr[] = $row[0];
    }

    return $arr;
}

function getTypes()
{
    global $db;

    if (!$db instanceof mysqli) {
        $db = connectDb();
    }

    $query = "SELECT id, name FROM " . PREF . "razd";

    $result = mysqli_query($db, $query);

    return getResult($result);
}

function getResult($result)
{
    global $db;

    if (!$db instanceof mysqli) {
        $db = connectDb();
    }

    if (!$result) {
        exit(mysqli_error($db));
    }

    if (mysqli_num_rows($result) === 0) {
        return false;
    }

    $row = [];

    for ($i = 0; mysqli_num_rows($result) > $i; $i++) {
        $row[] = mysqli_fetch_assoc($result);
    }

    return $row;
}

function getCategories()
{
    global $db;

    if (!$db instanceof mysqli) {
        $db = connectDb();
    }

    $query = "SELECT id, name, parent_id FROM " . PREF ."categories";

    $result = mysqli_query($db, $query);

    if (!$result) {
        exit(mysqli_error($db));
    }

    if (mysqli_num_rows($result) === 0) {
        return false;
    }

    $categories = [];

    for ($i = 0; mysqli_num_rows($result) > $i; $i++) {
        $row = mysqli_fetch_assoc($result);

        if (!$row['parent_id']) {
            $categories[$row['id']][] = $row['name'];
        } else {
            $categories[$row['parent_id']]['next'][$row['id']] = $row['name'];
        }
    }

    return $categories;
}

function getImg()
{
    $width = 160;
    $height = 80;

    $r = random_int(133, 255);
    $g = random_int(133, 255);
    $b = random_int(133, 255);

    $im = imagecreatetruecolor($width, $height);

    $background = imagecolorallocate($im, $r, $g, $b);

    imagefilledrectangle($im, 0, 0, $width, $height, $background);

    $black = imagecolorallocate($im, 7, 7, 7);

    for ($h = random_int(1, 10); $h < $height; $h += random_int(1, 10)) {
        for ($v = random_int(1, 30); $v < $width; $v += random_int(1, 30)) {
            imagesetpixel($im, $v, $h, $black);
        }
    }

    $str                = generateStr();
    $_SESSION['strCap'] = $str;

    $fontsP = "fonts/";

    $d = opendir($fontsP);

    while (false !== ($file = readdir($d))) {
        if ($file === "." || $file === "..") {
            continue;
        }
        $fonts[] = $file;
    }

    $x     = 20;
    $color = imagecolorallocate($im, 7, 7, 7);

    for ($i = 0; $i < strlen($str); $i++) {
        $n    = random_int(0, count($fonts) - 1);
        $font = $fontsP . $fonts[$n];

        $size  = random_int(15, 35);
        $angle = random_int(-30, 30);
        $y     = random_int(40, 45);

        imagettftext($im, $size, $angle, $x, $y, $color, $font, $str[$i]);
        $x = $x + $size - 5;
    }

    for ($c = 0; $c < 5; $c++) {
        $x1 = random_int(0, (int)($width * 0.1));
        $x2 = random_int((int)($width * 0.8), $width);

        $y1 = random_int(0, (int)($height * 0.6));
        $y2 = random_int((int)($width * 0.3), $height);

        imageline($im, $x1, $y1, $x2, $y2, $black);
    }

    header("Content-Type: image/png");
    imagepng($im);
    imagedestroy($im);
}

function generateStr(): string
{
    $str       = "23456789abcdegikpqsvxyz";
    $strLength = strlen($str) - 1;

    $strG = "";

    for ($i = 0; $i < 5; $i++) {

        $x = random_int(0, $strLength);

        if (($i !== 0) && $strG[strlen($strG) - 1] === $str[$x]) {
            $i--;
            continue;
        }

        $strG .= $str[$x];

    }

    return $strG;
}

function addMess($post, $userId)
{
    global $db;

    if (!$db instanceof mysqli) {
        $db = connectDb();
    }

    $title        = clearStr($post['title']);
    $text         = $post['text'];
    $idCategories = (int)($post['id_categories']);
    $idType       = (int)($post['id_razd']);
    $price        = (int)($post['price']);
    $town         = clearStr($post['town']);
    $date         = time();
    $aTime        = (int)($post['time']);
    $timeOver     = $date + ($aTime * (60 * 60 * 24));

    $msg = '';

    if(empty($_SESSION['strCap']) || $_SESSION['strCap'] !== $post['capcha']) {
        $_SESSION['p']['title'] = $title;
        $_SESSION['p']['text']  = $text;
        $_SESSION['p']['town']  = $town;
        $_SESSION['p']['price'] = $price;

        return "WRONG captcha";
    }

    unset($_SESSION['strCap']);

    if(empty($title)) {
        $msg .= "Input Title" . "<br>";
    }

    if(empty($text)) {
        $msg .= "Input Text" . "<br>";
    }

    if(empty($town)) {
        $msg .= "Input town" . "<br>";
    }

    if(empty($price)) {
        $msg .= "Input price" . "<br>";
    }

    if(!empty($msg)) {
        $_SESSION['p']['title'] = $title;
        $_SESSION['p']['text']  = $text;
        $_SESSION['p']['town']  = $town;
        $_SESSION['p']['price'] = $price;

        return $msg;
    }

    $imgTypes = [
        'jpeg'=>"image/jpeg",
        "pjpeg"=>"image/pjpeg",
        'png' => "image/png",
        'x-png' => "image/x-png",
        'gif' => "image/gif",
    ];

    if(!empty($_FILES['img']['tmp_name'])) {

        if(!empty($_FILES['img']['error'])) {
            $_SESSION['p']['title'] = $title;
            $_SESSION['p']['text']  = $text;
            $_SESSION['p']['town']  = $town;
            $_SESSION['p']['price'] = $price;

            return "Error upload image" . "<br>";
        }

        $typeImg = array_search($_FILES['img']['type'], $imgTypes, true);

        if(!$typeImg) {
            $_SESSION['p']['title'] = $title;
            $_SESSION['p']['text']  = $text;
            $_SESSION['p']['town']  = $town;
            $_SESSION['p']['price'] = $price;

            return "Wrong type image" . "<br>";
        }

        if ($_FILES['img']['size'] > IMG_SIZE) {
            $_SESSION['p']['title'] = $title;
            $_SESSION['p']['text']  = $text;
            $_SESSION['p']['town']  = $town;
            $_SESSION['p']['price'] = $price;

            return "Very big image" . "<br>";
        }

        if (!move_uploaded_file($_FILES['img']['tmp_name'], FILES . $_FILES['img']['name'])) {
            $_SESSION['p']['title'] = $title;
            $_SESSION['p']['text']  = $text;
            $_SESSION['p']['town']  = $town;
            $_SESSION['p']['price'] = $price;

            return "Error copy image" . "<br>";
        }

        if (!imgResize($_FILES['img']['name'], $typeImg)) {
            $_SESSION['p']['title'] = $title;
            $_SESSION['p']['text']  = $text;
            $_SESSION['p']['town']  = $town;
            $_SESSION['p']['price'] = $price;

            return "Error to resize image";
        }

        $img = $_FILES['img']['name'];

        $query = "
            INSERT INTO " . PREF . "post(
                title,
                text,
                img,
                date,
                id_user,
                id_categories,
                id_razd,
                town,
                time_over,
                price
            )
                VALUES (
                    '$title',
                    '$text',
                    '$img',
                    '$date',
                    '$userId',
                    '$idCategories',
                    '$idType',
                    '$town',
                    '$timeOver',
                    '$price'
                )
        ";

        $result = mysqli_query($db, $query);

        if(!$result) {
            $_SESSION['p']['title'] = $title;
            $_SESSION['p']['text']  = $text;
            $_SESSION['p']['town']  = $town;
            $_SESSION['p']['price'] = $price;

            return mysqli_error($db);
        }
    } else {
        $_SESSION['p']['title'] = $title;
        $_SESSION['p']['text']  = $text;
        $_SESSION['p']['town']  = $town;
        $_SESSION['p']['price'] = $price;

        return "Add image";
    }

    if(!empty($_FILES['mini'])) {
        $idMess = mysqli_insert_id($db);

        $imgS = "";

        for($i = 0; $i < count($_FILES['mini']['tmp_name']); $i++) {
            if(empty($_FILES['mini']['tmp_name'][$i])) {
                continue;
            }

            if(!empty($_FILES['mini']['error'][$i])) {
                $_SESSION['p']['title'] = $title;
                $_SESSION['p']['text']  = $text;
                $_SESSION['p']['town']  = $town;
                $_SESSION['p']['price'] = $price;

                $msg .= "Error upload image" . "<br>";
                continue;
            }

            $typeImg = array_search($_FILES['mini']['type'][$i], $imgTypes, true);

            if(!$typeImg) {
                $_SESSION['p']['title'] = $title;
                $_SESSION['p']['text']  = $text;
                $_SESSION['p']['town']  = $town;
                $_SESSION['p']['price'] = $price;

                $msg .= "Wrong type image" . "<br>";
                continue;
            }

            if ($_FILES['mini']['size'][$i] > IMG_SIZE) {
                $_SESSION['p']['title'] = $title;
                $_SESSION['p']['text']  = $text;
                $_SESSION['p']['town']  = $town;
                $_SESSION['p']['price'] = $price;

                $msg .= "Very big image" . "<br>";
                continue;
            }

            $nameImg = $idMess . "_" . $i;
            $rash    = substr($_FILES['mini']['name'][$i], strripos($_FILES['mini']['name'][$i], "."));
            $nameImg .= $rash;

            if (!move_uploaded_file($_FILES['mini']['tmp_name'][$i], FILES . $nameImg)) {
                $_SESSION['p']['title'] = $title;
                $_SESSION['p']['text']  = $text;
                $_SESSION['p']['town']  = $town;
                $_SESSION['p']['price'] = $price;

                $msg .= "Error copy image" . "<br>";
                continue;
            }


            if (!imgResize($nameImg, $typeImg)) {
                $_SESSION['p']['title'] = $title;
                $_SESSION['p']['text']  = $text;
                $_SESSION['p']['town']  = $town;
                $_SESSION['p']['price'] = $price;

                return "Error to resize image" . "<br>";
            }

            $imgS .= $nameImg . "|";
        }
        $imgS = rtrim($imgS, "|");

        $query = "UPDATE " . PREF . "post SET img_s = '$imgS' WHERE id = '$idMess'";

        $result2 = mysqli_query($db, $query);

        if(mysqli_affected_rows($db)) {
            if(!empty($msg)) {
                return $msg;
            }
            return true;
        }
    }
    else {
        return true;
    }
}

function imgResize($fileName, $type)
{
    switch ($type) {
        case 'jpeg':
        case 'pjpeg':
            $imgId = imagecreatefromjpeg(FILES . $fileName);
            break;
        case 'png':
        case 'x-png':
            $imgId = imagecreatefrompng(FILES . $fileName);
            break;
        case 'gif':
            $imgId = imagecreatefromgif(FILES . $fileName);
            break;
    }

    $imgWidth  = imageSX($imgId);
    $imgHeight = imageSY($imgId);

    $k = round($imgWidth / IMG_WIDTH, 2);

    $imgMiniWidth  = round($imgWidth / $k);
    $imgMiniHeight = round($imgHeight / $k);

    $imgDestId = imagecreatetruecolor($imgMiniWidth, $imgMiniHeight);

    $result = imagecopyresampled(
        $imgDestId,
        $imgId,
        0,
        0,
        0,
        0,
        $imgMiniWidth,
        $imgMiniHeight,
        $imgWidth,
        $imgHeight
    );

    $img = imagejpeg($imgDestId, MINI . $fileName, 100);

    imagedestroy($imgId);
    imagedestroy($imgDestId);

    if ($img) {
        return true;
    } else {
        return false;
    }
}
