<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

/* ============================================================
   DATABASE CONNECTION (PDO)
 ============================================================*/
function getDbConnection(): PDO
{
    $host = '127.0.0.1';
    $db   = 'filipino_cookbook_api';
    $user = 'root';
    $pass = ''; // 
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    return new PDO($dsn, $user, $pass, $options);
}

/*CONSTANTS*/
const API_TOKEN = 'dmmmsu-cookbook-token-2026';

/*HELPER: standard JSON response*/
function jsonResponse(Response $response, array $data, int $status = 200): Response
{
    $response->getBody()->write(json_encode($data));
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($status);
}

/*SLIM APP SETUP */
$app = AppFactory::create();

$app->addBodyParsingMiddleware();

// SECURE ERROR HANDLING (optional enhancement):
// displayErrorDetails is set to false so that unhandled errors do not
// leak stack traces, file paths, or server details to the client.
// Errors are still logged server-side (logErrors + logErrorDetails).
$app->addErrorMiddleware(false, true, true);

/* ============================================================
   TOKEN-BASED SECURITY MIDDLEWARE
   ============================================================ */
$authMiddleware = function (Request $request, $handler) {
    $authHeader = $request->getHeaderLine('Authorization');

    if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $response = new \Slim\Psr7\Response();
        return jsonResponse($response, [
            'status'  => 'error',
            'message' => 'Unauthorized access. Valid API token is required.',
        ], 401);
    }

    $token = trim($matches[1]);

    if ($token !== API_TOKEN) {
        $response = new \Slim\Psr7\Response();
        return jsonResponse($response, [
            'status'  => 'error',
            'message' => 'Unauthorized access. Valid API token is required.',
        ], 401);
    }

    return $handler->handle($request);
};

/*ROUTE 1: PUBLIC WELCOME ROUTE (no token required) GET */
$app->get('/', function (Request $request, Response $response) {
    return jsonResponse($response, [
        'message' => 'Welcome to the Secured Filipino Cookbook API',
        'note'    => 'Use a valid Bearer token to access /api endpoints.',
    ]);
});

/* ============================================================
   SECURED /api ROUTE GROUP
   ============================================================ */
$app->group('/api', function ($group) {

    /*ROUTE 2: GET ALL FOODS GET /api/foods */
    $group->get('/foods', function (Request $request, Response $response) {
        $db = getDbConnection();

        $stmt = $db->query("
            SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions
            FROM foods f
            JOIN categories c ON f.category_id = c.category_id
            JOIN origins o ON f.origin_id = o.origin_id
            ORDER BY f.food_id ASC
        ");
        $foods = $stmt->fetchAll();

        $ingStmt = $db->prepare("
            SELECT i.ingredient_name
            FROM food_ingredients fi
            JOIN ingredients i ON fi.ingredient_id = i.ingredient_id
            WHERE fi.food_id = :food_id
            ORDER BY i.ingredient_name ASC
        ");

        foreach ($foods as &$food) {
            $ingStmt->execute(['food_id' => $food['food_id']]);
            $food['ingredients'] = array_column($ingStmt->fetchAll(), 'ingredient_name');
        }

        return jsonResponse($response, $foods);
    });

    /* ==========================================================
       NEW (OPTIONAL ENHANCEMENT) - ROUTE: GET FOODS BY CATEGORY
       GET /api/categories/{id}/foods
       ========================================================== */
    $group->get('/categories/{id}/foods', function (Request $request, Response $response, array $args) {
        $db = getDbConnection();
        $categoryId = (int) $args['id'];

        $checkStmt = $db->prepare("SELECT category_name FROM categories WHERE category_id = :id");
        $checkStmt->execute(['id' => $categoryId]);
        $category = $checkStmt->fetch();

        if (!$category) {
            return jsonResponse($response, [
                'status'  => 'error',
                'message' => 'Category not found',
            ], 404);
        }

        $stmt = $db->prepare("
            SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions
            FROM foods f
            JOIN categories c ON f.category_id = c.category_id
            JOIN origins o ON f.origin_id = o.origin_id
            WHERE f.category_id = :id
            ORDER BY f.food_id ASC
        ");
        $stmt->execute(['id' => $categoryId]);
        $foods = $stmt->fetchAll();

        $ingStmt = $db->prepare("
            SELECT i.ingredient_name
            FROM food_ingredients fi
            JOIN ingredients i ON fi.ingredient_id = i.ingredient_id
            WHERE fi.food_id = :food_id
            ORDER BY i.ingredient_name ASC
        ");

        foreach ($foods as &$food) {
            $ingStmt->execute(['food_id' => $food['food_id']]);
            $food['ingredients'] = array_column($ingStmt->fetchAll(), 'ingredient_name');
        }

        return jsonResponse($response, $foods);
    });

    /* ==========================================================
       NEW (OPTIONAL ENHANCEMENT) - ROUTE: NUMBER OF FOODS PER CATEGORY
       GET /api/categories/summary
       ========================================================== */
    $group->get('/categories/summary', function (Request $request, Response $response) {
        $db = getDbConnection();

        $stmt = $db->query("
            SELECT c.category_name, COUNT(f.food_id) AS total_foods
            FROM categories c
            LEFT JOIN foods f ON f.category_id = c.category_id
            GROUP BY c.category_id, c.category_name
            ORDER BY c.category_id ASC
        ");
        $summary = $stmt->fetchAll();

        return jsonResponse($response, $summary);
    });

    /* ==========================================================
       NEW (OPTIONAL ENHANCEMENT) - ROUTE: GET A RANDOM FOOD
       GET /api/foods/random
       NOTE: registered BEFORE /foods/{id} so "random" is not
       mistaken for an {id} value.
       ========================================================== */
    $group->get('/foods/random', function (Request $request, Response $response) {
        $db = getDbConnection();

        $stmt = $db->query("
            SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions
            FROM foods f
            JOIN categories c ON f.category_id = c.category_id
            JOIN origins o ON f.origin_id = o.origin_id
            ORDER BY RAND()
            LIMIT 1
        ");
        $food = $stmt->fetch();

        if (!$food) {
            return jsonResponse($response, [
                'status'  => 'error',
                'message' => 'No foods available',
            ], 404);
        }

        $ingStmt = $db->prepare("
            SELECT i.ingredient_name
            FROM food_ingredients fi
            JOIN ingredients i ON fi.ingredient_id = i.ingredient_id
            WHERE fi.food_id = :food_id
            ORDER BY i.ingredient_name ASC
        ");
        $ingStmt->execute(['food_id' => $food['food_id']]);
        $food['ingredients'] = array_column($ingStmt->fetchAll(), 'ingredient_name');

        return jsonResponse($response, $food);
    });

    /*ROUTE 3: GET FOOD BY ID GET /api/foods/{id}*/
    $group->get('/foods/{id}', function (Request $request, Response $response, array $args) {
        $db = getDbConnection();
        $id = (int) $args['id'];

        $stmt = $db->prepare("
            SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions
            FROM foods f
            JOIN categories c ON f.category_id = c.category_id
            JOIN origins o ON f.origin_id = o.origin_id
            WHERE f.food_id = :id
        ");
        $stmt->execute(['id' => $id]);
        $food = $stmt->fetch();

        if (!$food) {
            return jsonResponse($response, [
                'status'  => 'error',
                'message' => 'Food not found',
            ], 404);
        }

        $ingStmt = $db->prepare("
            SELECT i.ingredient_name
            FROM food_ingredients fi
            JOIN ingredients i ON fi.ingredient_id = i.ingredient_id
            WHERE fi.food_id = :food_id
            ORDER BY i.ingredient_name ASC
        ");
        $ingStmt->execute(['food_id' => $id]);
        $food['ingredients'] = array_column($ingStmt->fetchAll(), 'ingredient_name');

        return jsonResponse($response, $food);
    });

    /* ROUTE 4: SEARCH FOOD BY NAME GET /api/foods/search/{name} */
    $group->get('/foods/search/{name}', function (Request $request, Response $response, array $args) {
        $db = getDbConnection();
        $name = $args['name'];

        $stmt = $db->prepare("
            SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions
            FROM foods f
            JOIN categories c ON f.category_id = c.category_id
            JOIN origins o ON f.origin_id = o.origin_id
            WHERE f.food_name LIKE :name
            ORDER BY f.food_id ASC
        ");
        $stmt->execute(['name' => '%' . $name . '%']);
        $foods = $stmt->fetchAll();

        $ingStmt = $db->prepare("
            SELECT i.ingredient_name
            FROM food_ingredients fi
            JOIN ingredients i ON fi.ingredient_id = i.ingredient_id
            WHERE fi.food_id = :food_id
            ORDER BY i.ingredient_name ASC
        ");

        foreach ($foods as &$food) {
            $ingStmt->execute(['food_id' => $food['food_id']]);
            $food['ingredients'] = array_column($ingStmt->fetchAll(), 'ingredient_name');
        }

        return jsonResponse($response, $foods);
    });

    /*ROUTE 5: GET ALL CATEGORIES GET /api/categories*/
    $group->get('/categories', function (Request $request, Response $response) {
        $db = getDbConnection();
        $stmt = $db->query("SELECT category_id, category_name FROM categories ORDER BY category_id ASC");
        return jsonResponse($response, $stmt->fetchAll());
    });

    /* ROUTE 6: GET ALL INGREDIENTS GET /api/ingredients */
    $group->get('/ingredients', function (Request $request, Response $response) {
        $db = getDbConnection();
        $stmt = $db->query("SELECT ingredient_id, ingredient_name FROM ingredients ORDER BY ingredient_id ASC");
        return jsonResponse($response, $stmt->fetchAll());
    });

    /* --------------------------------------------------------
       ROUTE 7: ADD NEW FOOD
       POST /api/foods
       -------------------------------------------------------- */
    $group->post('/foods', function (Request $request, Response $response) {
        $db = getDbConnection();
        $data = $request->getParsedBody();

        $required = ['food_name', 'category_id', 'origin_id', 'instructions', 'ingredient_ids'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => "Missing required field: {$field}",
                ], 400);
            }
        }

        if (!is_array($data['ingredient_ids']) || count($data['ingredient_ids']) === 0) {
            return jsonResponse($response, [
                'status'  => 'error',
                'message' => 'ingredient_ids must be a non-empty array.',
            ], 400);
        }

        try {
            $db->beginTransaction();

            // food_id is not AUTO_INCREMENT in the schema, so we first // get the next available ID.
            $nextIdStmt = $db->query("SELECT COALESCE(MAX(food_id), 0) + 1 AS next_id FROM foods");
            $nextId = (int) $nextIdStmt->fetch()['next_id'];

            $insertFood = $db->prepare("
                INSERT INTO foods (food_id, food_name, category_id, origin_id, instructions)
                VALUES (:food_id, :food_name, :category_id, :origin_id, :instructions)
            ");
            $insertFood->execute([
                'food_id'      => $nextId,
                'food_name'    => $data['food_name'],
                'category_id'  => $data['category_id'],
                'origin_id'    => $data['origin_id'],
                'instructions' => $data['instructions'],
            ]);

            $insertIngredient = $db->prepare("
                INSERT INTO food_ingredients (food_id, ingredient_id)
                VALUES (:food_id, :ingredient_id)
            ");
            foreach ($data['ingredient_ids'] as $ingredientId) {
                $insertIngredient->execute([
                    'food_id'       => $nextId,
                    'ingredient_id' => $ingredientId,
                ]);
            }

            $db->commit();

            return jsonResponse($response, [
                'status'  => 'success',
                'message' => 'Food added successfully.',
            ], 201);

        } catch (PDOException $e) {
            $db->rollBack();

            // SECURE ERROR HANDLING (optional enhancement):
            // Huwag ibalik ang raw database error message sa client --
            // pwedeng maglabas ito ng sensitive info gaya ng table/column
            // names o SQL structure. Sa halip, generic message lang ang
            // ipapakita, at ang detalyadong error ay ilalagay sa PHP
            // error log (server-side lang, hindi makikita ng user).
            error_log('POST /api/foods failed: ' . $e->getMessage());

            return jsonResponse($response, [
                'status'  => 'error',
                'message' => 'Failed to add food. Please check your input and try again.',
            ], 500);
        }
    });

})->add($authMiddleware);

$app->run();