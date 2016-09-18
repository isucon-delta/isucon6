<?php
namespace Isuda\Web;

use Slim\Http\Request;
use Slim\Http\Response;
use PDO;
use PDOWrapper;


function config($key) {
    static $conf;
    if ($conf === null) {
        $conf = [
            'dsn'           => $_ENV['ISUDA_DSN']         ?? 'dbi:mysql:db=isuda',
            'db_user'       => $_ENV['ISUDA_DB_USER']     ?? 'isucon',
            'db_password'   => $_ENV['ISUDA_DB_PASSWORD'] ?? 'isucon',
            'isutar_origin' => $_ENV['ISUTAR_ORIGIN']     ?? 'http://localhost:5001',
            'isupam_origin' => $_ENV['ISUPAM_ORIGIN']     ?? 'http://localhost:5050',
        ];
    }

    if (empty($conf[$key])) {
        exit("config value of $key undefined");
    }
    return $conf[$key];
}

$container = new class extends \Slim\Container {
    public $dbh;
    public $redis;
    public function __construct() {
        parent::__construct();

        $this->dbh = new PDOWrapper(new PDO(
            $_ENV['ISUDA_DSN'],
            $_ENV['ISUDA_DB_USER'] ?? 'isucon',
            $_ENV['ISUDA_DB_PASSWORD'] ?? 'isucon',
            [ PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4" ]
        ));
        $this->redis = new \Redis();
        $this->redis->connect('127.0.0.1', 6379);
    }

    public function get_keyword_replace_pairs(){
        $keywords = $this->redis-> zRevRange('keywords', 0, -1);
        //$keywords = $this->dbh->select_all(
        //    'SELECT keyword FROM entry ORDER BY keyword_length DESC'
        //);

        $rep =array();

	foreach ($keywords as $keyword){
		$rep[$keyword] = sprintf('<a href="/keyword/%s">%s</a>',
			rawurlencode($keyword),
			htmlspecialchars($keyword, ENT_COMPAT | ENT_HTML401, 'UTF-8')
		);
	}

        return $rep;
    }

    public function htmlify($content, $keywords_pairs) {
        if (!isset($content)) {
            return '';
        }
	$content = strtr($content, $keywords_pairs);
	return nl2br($content, true);
   }

    public function load_stars($keyword) {
        $stars = $this->redis->lRange("star:$keyword", 0, -1);
        return $stars;
    }
};
$container['view'] = function ($container) {
    $view = new \Slim\Views\Twig($_ENV['PHP_TEMPLATE_PATH'], []);
    $view->addExtension(new \Slim\Views\TwigExtension(
        $container['router'],
        $container['request']->getUri()
    ));
    return $view;
};
$container['stash'] = new \Pimple\Container;


$app = new \Slim\App($container);



$mw = [];
// compatible filter 'set_name'
$mw['set_name'] = function ($req, $c, $next) {

    $this->get('stash')['user_id'] = $_COOKIE['user_id'];
    $this->get('stash')['user_name'] = $_COOKIE['user_name'];
	/*
    $this->get('stash')['user_id'] = $_SESSION['user_id'];
    $this->get('stash')['user_name'] = $_SESSION['user_name'];
	 */
    return $next($req, $c);
};

$mw['authenticate'] = function ($req, $c, $next) {
    if (!isset($this->get('stash')['user_id'])) {
        return $c->withStatus(403);
    }
    return $next($req, $c);
};

$app->get('/redis', function (Request $req, Response $c) {
    return render_json($c, [
        'result' => $this->redis->ping(),
    ]);
});

$app->get('/initialize', function (Request $req, Response $c) {
    $this->dbh->query(
        'DELETE FROM entry WHERE id > 7101'
    );

    $this->redis->flushAll();

    $entries = $this->dbh->select_all(
        'SELECT description, keyword, keyword_length  from entry'
    );
    foreach ($entries as &$entry) {
        $this->redis->zAdd('keywords', $entry['keyword_length'], $entry['keyword']);
        $this->redis->set($entry['keyword'], $entry['description']);
    }

    $this->dbh->query('TRUNCATE star');
    return render_json($c, [
        'result' => 'ok',
    ]);
});

//$app->get('/stars', function (Request $req, Response $c) {
//    $stars = $this->dbh->select_all(
//        'SELECT * FROM star WHERE keyword = ?'
//    , $req->getParams()['keyword']);
//
//    return render_json($c, [
//        'stars' => $stars,
//    ]);
//});

$app->post('/stars', function (Request $req, Response $c) {
    $keyword = $req->getParams()['keyword'];

    if (empty($this->redis->get($keyword))) {
        $entry = $this->dbh->select_row(
           'SELECT id FROM entry'
            .' WHERE keyword = ?'
          , $keyword);
        if (empty($entry)) return $c->withStatus(404);
	}

    $this->redis->rpush("star:$keyword", $req->getParams()['user']);

    return render_json($c, [
        'result' => 'ok',
    ]);
});

$app->get('/', function (Request $req, Response $c) {

    $PER_PAGE = 10;
    $page = $req->getQueryParams()['page'] ?? 1;

    $offset = $PER_PAGE * ($page-1);
    $entries = $this->dbh->select_all(
        'SELECT description, keyword FROM entry '.
        'ORDER BY updated_at DESC '.
        "LIMIT $PER_PAGE ".
        "OFFSET $offset"
    );

    $keywords = $this->get_keyword_replace_pairs();
     foreach ($entries as &$entry) {
        $entry['html']  = $this->htmlify($entry['description'], $keywords);
        $entry['stars'] = $this->load_stars($entry['keyword']);
    }
    unset($entry);

    $total_entries = $this->dbh->select_one(
        'SELECT COUNT(*) FROM entry'
    );
    $last_page = ceil($total_entries / $PER_PAGE);
    $pages = range(max(1, $page-5), min($last_page, $page+5));

    $this->view->render($c, 'index.twig', [ 'entries' => $entries, 'page' => $page, 'last_page' => $last_page, 'pages' => $pages, 'stash' => $this->get('stash') ]);
})->add($mw['set_name'])->setName('/');

$app->get('/robots.txt', function (Request $req, Response $c) {
    return $c->withStatus(404);
});

$app->post('/keyword', function (Request $req, Response $c) {
    $keyword = $req->getParsedBody()['keyword'];
    if (!isset($keyword)) {
        return $c->withStatus(400)->write("'keyword' required");
    }
    $user_id = $this->get('stash')['user_id'];
    $description = $req->getParsedBody()['description'];

    if (is_spam_contents($description .' '. $keyword)) {
        return $c->withStatus(400)->write('SPAM!');
    }
    $this->dbh->query(
        'INSERT INTO entry (author_id, keyword, keyword_length, description, created_at, updated_at)'
        .' VALUES (?, ?, CHARACTER_LENGTH(?), ?, NOW(), NOW())'
        .' ON DUPLICATE KEY UPDATE'
        .' author_id = ?, keyword = ?, description = ?, updated_at = NOW()'
    , $user_id, $keyword, $keyword, $description, $user_id, $keyword, $description);
    $this->redis->zAdd('keywords' , strlen($keyword), $keyword);

    return $c->withRedirect('/');
})->add($mw['authenticate'])->add($mw['set_name']);

$app->get('/register', function (Request $req, Response $c) {
    return $this->view->render($c, 'authenticate.twig', [
        'action' => 'register', 'stash' => $this->get('stash')
    ]);
})->add($mw['set_name'])->setName('/register');

$app->post('/register', function (Request $req, Response $c) {
    $name = $req->getParsedBody()['name'];
    $pw   = $req->getParsedBody()['password'];
    if ($name === '' || $pw === '') {
        return $c->withStatus(400);
    }
    $user_id = register($this->dbh, $name, $pw);

    setcookie('user_id', $user_id);
    setcookie('user_name', $name);

    /*
    $_SESSION['user_id'] = $user_id;
    $_SESSION['user_name'] = $name;
     */
    return $c->withRedirect('/');
});

function register($dbh, $user, $pass) {
    $salt = random_string('....................');
    $dbh->query(
        'INSERT INTO user (name, salt, password, created_at)'
        .' VALUES (?, ?, ?, NOW())'
    , $user, $salt, sha1($salt . $pass));

    return $dbh->last_insert_id();
}

$app->get('/login', function (Request $req, Response $c) {
    return $this->view->render($c, 'authenticate.twig', [
        'action' => 'login', 'stash' => $this->get('stash')
    ]);
})->add($mw['set_name'])->setName('/login');

$app->post('/login', function (Request $req, Response $c) {
    $name = $req->getParsedBody()['name'];
    $row = $this->dbh->select_row(
        'SELECT * FROM user'
        . ' WHERE name = ?'
    , $name);
    if (!$row || $row['password'] !== sha1($row['salt'].$req->getParsedBody()['password'])) {
        return $c->withStatus(403);
    }

    setcookie('user_id', $row['id']);
    setcookie('user_name', $row['name']);
    /*
    $_SESSION['user_id'] = $row['id'];
    $_SESSION['user_name'] = $row['name'];
     */
    return $c->withRedirect('/');
});

$app->get('/logout', function (Request $req, Response $c) {
    setcookie('user_id', '');
    setcookie('user_name', '');
	/*
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-60, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
	 */
    return $c->withRedirect('/');
});

$app->get('/keyword/{keyword}', function (Request $req, Response $c) {
    $keyword = $req->getAttribute('keyword');
    if ($keyword === null) return $c->withStatus(400);

    $entry = array();
    if (empty($this->redis->get($keyword))) {
        $entry = $this->dbh->select_row(
            'SELECT description, keyword FROM entry'
           .' WHERE keyword = ?'
           , $keyword);
        if (empty($entry)) return $c->withStatus(404);
	} else {
        $entry['keyword'] = $keyword; 
        $entry['description'] = $this->redis->get($keyword);
	}

    $keywords = $this->get_keyword_replace_pairs();

    $entry['html'] = $this->htmlify($entry['description'], $keywords);
    $entry['stars'] = $this->load_stars($entry['keyword']);

    return $this->view->render($c, 'keyword.twig', [
        'entry' => $entry, 'stash' => $this->get('stash')
    ]);
})->add($mw['set_name']);

$app->post('/keyword/{keyword}', function (Request $req, Response $c) {
    $param = $req->getParsedBody();
    $keyword = $param['keyword'];
    $delete = $param['delete'];
    if ($keyword === null || $delete == null) return $c->withStatus(400);

    $entry = $this->dbh->select_row(
        'SELECT * FROM entry'
        .' WHERE keyword = ?'
    , $keyword);
    if (empty($entry)) return $c->withStatus(404);

    $this->dbh->query('DELETE FROM entry WHERE keyword = ?', $keyword);
    return $c->withRedirect('/');
})->add('authenticate')->add($mw['set_name']);

function is_spam_contents($content) {
    $ua = new \GuzzleHttp\Client;
    $res = $ua->request('POST', config('isupam_origin'), [
        'form_params' => ['content' => $content]
    ])->getBody();
    return $res != '{"valid":true}';
}

$app->run();
