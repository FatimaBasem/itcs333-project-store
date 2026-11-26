<?php
declare(strict_types=1);

/**
 * m4_orders — checkout, order history, order detail.
 * Contract: contracts/orders.md
 * Routing context comes from $GLOBALS['__route'] (set by public/api.php).
 */

$rest = $GLOBALS['__route']['rest'] ?? '';
$method = http_method();
$pdo = db();

/** Return the logged-in user id or answer 401. */
function m4_require_user(): int
{
    if (empty($_SESSION['user_id'])) {
        send_error(401, 'AUTH_REQUIRED');
    }
    return (int) $_SESSION['user_id'];
}

function m4_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function m4_is_admin(PDO $pdo, int $uid): bool
{
    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    return $row && (int) $row['is_admin'] === 1;
}

/** Build the full order shape (order + items) from an orders row. */
function m4_order_with_items(PDO $pdo, array $order): array
{
    $stmt = $pdo->prepare(
        'SELECT product_id, qty, unit_price FROM order_items WHERE order_id = ? ORDER BY id'
    );
    $stmt->execute([(int) $order['id']]);
    $items = array_map(fn($i) => [
        'product_id' => (int) $i['product_id'],
        'qty' => (int) $i['qty'],
        'unit_price' => (float) $i['unit_price'],
    ], $stmt->fetchAll());

    return [
        'id' => (int) $order['id'],
        'user_id' => (int) $order['user_id'],
        'total' => (float) $order['total'],
        'created_at' => $order['created_at'],
        'items' => $items,
    ];
}

switch (true) {
    // ---------- POST /api/orders : checkout ----------
    case $method === 'POST' && $rest === '': {
        $uid = m4_require_user();
        $createdAt = m4_now();
        $failure = null; // [status, code, extra]
        $orderId = 0;
        $total = 0.0;

        $pdo->beginTransaction();
        try {
            $cart = $pdo->prepare(
                'SELECT c.product_id, c.qty, p.price
                 FROM cart_items c JOIN products p ON p.id = c.product_id
                 WHERE c.user_id = ? ORDER BY c.id'
            );
            $cart->execute([$uid]);
            $lines = $cart->fetchAll();

            if (!$lines) {
                $failure = [400, 'CART_EMPTY', []];
            } else {
                foreach ($lines as $l) {
                    $total += (float) $l['price'] * (int) $l['qty'];
                }
                $total = round($total, 2);

                $orderId = db_insert_and_get_id($pdo, 'orders', [
                    'user_id' => $uid,
                    'total' => $total,
                    'created_at' => $createdAt,
                ]);

                $insItem = $pdo->prepare(
                    'INSERT INTO order_items (order_id, product_id, qty, unit_price) VALUES (?, ?, ?, ?)'
                );
                // Conditional decrement: only succeeds if enough stock remains.
                $decStock = $pdo->prepare(
                    'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
                );

                foreach ($lines as $l) {
                    $pid = (int) $l['product_id'];
                    $qty = (int) $l['qty'];
                    $decStock->execute([$qty, $pid, $qty]);
                    if ($decStock->rowCount() !== 1) {
                        $failure = [409, 'INSUFFICIENT_STOCK', ['product_id' => $pid]];
                        break;
                    }
                    $insItem->execute([$orderId, $pid, $qty, (float) $l['price']]);
                }

                if ($failure === null) {
                    $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$uid]);
                }
            }

            if ($failure !== null) {
                $pdo->rollBack();
            } else {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            send_error(500, 'SERVER_ERROR');
        }

        if ($failure !== null) {
            send_error($failure[0], $failure[1], $failure[2]);
        }

        send_json(201, m4_order_with_items($pdo, [
            'id' => $orderId,
            'user_id' => $uid,
            'total' => $total,
            'created_at' => $createdAt,
        ]));
    }

    // ---------- GET /api/orders : my order history ----------
    case $method === 'GET' && $rest === '': {
        $uid = m4_require_user();
        $stmt = $pdo->prepare(
            'SELECT id, total, created_at FROM orders WHERE user_id = ? ORDER BY id'
        );
        $stmt->execute([$uid]);
        $rows = array_map(fn($o) => [
            'id' => (int) $o['id'],
            'total' => (float) $o['total'],
            'created_at' => $o['created_at'],
        ], $stmt->fetchAll());
        send_json(200, ['data' => $rows]);
    }

    // ---------- GET /api/orders/{id} : one order (owner or admin) ----------
    case $method === 'GET' && preg_match('#^/(\d+)$#', $rest, $mm) === 1: {
        $uid = m4_require_user();
        $id = param_id($mm[1]);
        if ($id === null) {
            send_error(404, 'NOT_FOUND');
        }
        $stmt = $pdo->prepare('SELECT id, user_id, total, created_at FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) {
            send_error(404, 'NOT_FOUND');
        }
        if ((int) $order['user_id'] !== $uid && !m4_is_admin($pdo, $uid)) {
            send_error(403, 'FORBIDDEN');
        }
        send_json(200, m4_order_with_items($pdo, $order));
    }

    default:
        send_error(404, 'NOT_FOUND');
}