<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require __DIR__ . '/../vendor/autoload.php';
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

$ManagePanel = new ManagePanel();

// CubePay hits this URL two ways: a browser redirect (GET, after the payer is
// done) and a server-to-server POST (JSON body) — read authority/order_id from
// whichever is present.
$rawInput = file_get_contents('php://input');
$jsonInput = $rawInput ? json_decode($rawInput, true) : null;
$jsonInput = is_array($jsonInput) ? $jsonInput : [];

$authority = $jsonInput['authority'] ?? ($_REQUEST['authority'] ?? '');
$data_order_id = $jsonInput['order_id'] ?? ($_REQUEST['order_id'] ?? '');
$authority = htmlspecialchars($authority, ENT_QUOTES, 'UTF-8');
$data_order_id = htmlspecialchars($data_order_id, ENT_QUOTES, 'UTF-8');

$Payment_report = select("Payment_report", "*", "id_order", $data_order_id, "select");
if (!$Payment_report)
    return;
$token_cubepay = select("PaySetting", "*", "NamePay", "apiternado", "select")['ValuePay'];
if ($Payment_report['payment_Status'] == "expire")
    return;
$setting = select("setting", "*", null, null, "select");
$price = $Payment_report['price'];
if ($Payment_report['payment_Status'] != "paid" && $authority) {
    $ch = curl_init('https://cubevps.ir/smspay/api/verify-payment.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['authority' => $authority]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token_cubepay
    ));
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $response = json_decode($result, true);

    // Never trust the callback's own claim of success — re-derive it from
    // verify-payment, and cross-check order_id + amount so a stray/forged
    // callback can't credit the wrong invoice. 409 (already verified by an
    // earlier call — CubePay hits this URL via both browser redirect and
    // server-to-server POST) is treated as success too, gated by the SAME
    // amount/order_id check, then de-duped below by our own payment_Status.
    $amount_rial = intval($price) * 10;
    $isVerifiedForThisOrder = is_array($response)
        && isset($response['order_id'], $response['amount'])
        && (string) $response['order_id'] === (string) $data_order_id
        && intval($response['amount']) === $amount_rial;

    if ((($httpCode == 200 && !empty($response['success'])) || $httpCode == 409) && $isVerifiedForThisOrder) {
        echo json_encode(array("status" => true));
        $textbotlang = languagechange();
        DirectPayment($data_order_id, "../images.jpg");
        $pricecashback = select("PaySetting", "ValuePay", "NamePay", "chashbackiranpay2", "select")['ValuePay'];
        $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
        if ($pricecashback != "0") {
            $result_cashback = ($Payment_report['price'] * $pricecashback) / 100;
            $Balance_confrim = intval($Balance_id['Balance']) + $result_cashback;
            update("user", "Balance", $Balance_confrim, "id", $Balance_id['id']);
            $pricecashback = number_format($pricecashback);
            $text_report = sprintf($textbotlang['paymentGateway']['giftReport'], $result_cashback);
            sendmessage($Balance_id['id'], $text_report, null, 'HTML');
        }
        $paymentreports = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'];
        $text_reportpayment = sprintf($textbotlang['paymentGateway']['reportTronado'], $Balance_id['username'], $Balance_id['id'], $price);
        $Status_change = "paid";
        $statement = $pdo->prepare("UPDATE Payment_report SET payment_Status = :payment_Status WHERE id_order = :id_order");
        $statement->bindValue(':payment_Status', $Status_change);
        $statement->bindValue(':id_order', $Payment_report['id_order']);
        $statement->execute();
        $database = json_encode($response);
        $statement = $pdo->prepare("UPDATE Payment_report SET dec_not_confirmed = :dec_not_confirmed WHERE id_order = :id_order");
        $statement->bindValue(':dec_not_confirmed', $database);
        $statement->bindValue(':id_order', $Payment_report['id_order']);
        $statement->execute();
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $paymentreports,
                'text' => $text_reportpayment,
                'parse_mode' => "HTML"
            ]);
        }
    } else {
        echo json_encode(array("status" => false));
    }
}
