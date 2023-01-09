<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 *
 * Libraries Paytr
 *
 * This Libraries for ...
 *
 * @package   CodeIgniter
 * @category  Libraries
 * @author    Ufukcan Eski <ufukcan.b@hotmail.com>
 * @link      https://ufukcaneski.com
 *
 */

class Paytr
{
  private $merchant_id = 'your_merchant_id';
  private $merchant_key = 'your_merchant_key';
  private $merchant_salt = 'your_merchant_salt';
  private $merchant_ok_url;
  private $merchant_fail_url;

  // ------------------------------------------------------------------------

  public function __construct()
  {
    // 
  }

  // ------------------------------------------------------------------------


  // ------------------------------------------------------------------------

  public function index()
  {
    // 
  }

  // ------------------------------------------------------------------------

  public function curl($url, $bin_number)
  {

    ####### Bu kısımda herhangi bir değişiklik yapmanıza gerek yoktur. #######
    $hash_str = $bin_number . $this->merchant_id . $this->merchant_salt;
    $paytr_token = base64_encode(hash_hmac('sha256', $hash_str, $this->merchant_key, true));
    $post_vals = array(
      'merchant_id' => $this->merchant_id,
      'bin_number' => $bin_number,
      'paytr_token' => $paytr_token
    );

    /* Init cURL resource */
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_vals);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    //XXX: DİKKAT: lokal makinanızda "SSL certificate problem: unable to get local issuer certificate" uyarısı alırsanız eğer
    //aşağıdaki kodu açıp deneyebilirsiniz. ANCAK, güvenlik nedeniyle sunucunuzda (gerçek ortamınızda) bu kodun kapalı kalması çok önemlidir!
    //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    $result = curl_exec($ch);
    curl_close($ch);

    return json_decode($result, true);
  }

  public function check_basket($user_id, $basket) //$basket => [id, qty]
  {
    $ci = &get_instance();
    $user_basket = array();
    $user_basket_details = array();
    $payment_amount = 0;
    foreach ($basket as $b) {
      $credit = $ci->db->from("credits")->where("id", $b["id"])->get()->row();
      array_push($user_basket, array($credit->name, (string)number_format($credit->price, 2, '.', ','), $b["qty"]));
      $payment_amount += $credit->price * $b["qty"];
      array_push($user_basket_details, array(
        "id" => $b["id"],
        "name" => $credit->name,
        "credit_qty" => $credit->qty,   //paketin kontör adedi
        "package_qty" => $b["qty"],  //fronttan gelen adet
        "price" => (string)number_format($credit->price, 2, '.', ',')
      ));
    }
    $user = $ci->db->where('user_id', $user_id)->get('users')->row_array();
    $company = $ci->db->from("company_info c")->join("employees e", "e.user_id = $user_id", "left")->get()->row_array();

    $user_basket = htmlentities(json_encode($user_basket));
    $payment_amount = number_format($payment_amount, 2, '.', ',');
    $this->merchant_ok_url = base_url("payment/success");
    $this->merchant_fail_url = base_url("payment/error");
    srand(time());
    $merchant_oid = "PLT" . rand(100000, 999999) . rand(100000, 999999);
    $test_mode = "0";
    //3d'siz işlem
    $non_3d = "0";
    //Ödeme süreci dil seçeneği tr veya en
    $client_lang = "tr";
    //non3d işlemde, başarısız işlemi test etmek için 1 gönderilir (test_mode ve non_3d değerleri 1 ise dikkate alınır!)
    $non3d_test_failed = "0";

    if (isset($_SERVER["HTTP_CLIENT_IP"])) {
      $ip = $_SERVER["HTTP_CLIENT_IP"];
    } elseif (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
      $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
    } else {
      $ip = $_SERVER["REMOTE_ADDR"];
    }

    $user_ip = $ip;
    // $email = "kaplan1912@gmail.com";    //müşteri e-posta adresi olacak. post işleminden veya db'den bastrıılabilir.
    $email = $user['email'];
    $currency = "TL";
    $payment_type = "card";
    // $card_type = "bonus";       // Alabileceği değerler; advantage, axess, combo, bonus, cardfinans, maximum, paraf, world, saglamkart
    $installment_count = "0";
    $post_url = "https://www.paytr.com/odeme";
    $hash_str = $this->merchant_id . $user_ip . $merchant_oid . $email . $payment_amount . $payment_type . $installment_count . $currency . $test_mode . $non_3d;
    $token = base64_encode(hash_hmac('sha256', $hash_str . $this->merchant_salt, $this->merchant_key, true));



    $data = array(
      "merchant_id" => $this->merchant_id,
      "user_name" => $user["name"] . ' ' . $user['surname'],
      "user_address" => $company["address"],
      "user_phone" => $company['company_phone'],
      "user_ip" => $user_ip,
      "merchant_oid" => $merchant_oid,
      "email" => $email,
      "payment_type" => $payment_type,
      "payment_amount" => $payment_amount,
      "currency" => $currency,
      "test_mode" => $test_mode,
      "non_3d" => $non_3d,
      "merchant_ok_url" => $this->merchant_ok_url,
      "merchant_fail_url" => $this->merchant_fail_url,
      "user_basket" => $user_basket,
      "client_lang" => $client_lang,
      "token" => $token,
      "non3d_test_failed" => $non3d_test_failed,
      "installment_count" => $installment_count,
      "post_url" => $post_url
    );

    return array(
      "company" => $company,
      "data" => $data,
      "basket_details" => $user_basket_details
    );
  }

  public function callback($data)
  {
    ####### Bu kısımda herhangi bir değişiklik yapmanıza gerek yoktur. #######
    #

    $hash = base64_encode(hash_hmac('sha256', $data['merchant_oid'] . $this->merchant_salt . $data['status'] . $data['total_amount'], $this->merchant_key, true));
    #
    ## Oluşturulan hash'i, paytr'dan gelen post içindeki hash ile karşılaştır (isteğin paytr'dan geldiğine ve değişmediğine emin olmak için)
    ## Bu işlemi yapmazsanız maddi zarara uğramanız olasıdır.
    if ($hash != $data['hash'])
      die('PAYTR notification failed: bad hash');
    ###########################################################################

    ## BURADA YAPILMASI GEREKENLER
    ## 1) Siparişin durumunu $post['merchant_oid'] değerini kullanarak veri tabanınızdan sorgulayın.
    ## 2) Eğer sipariş zaten daha önceden onaylandıysa veya iptal edildiyse  echo "OK"; exit; yaparak sonlandırın.

    /* Sipariş durum sorgulama örnek
           $durum = SQL
           if($durum == "onay" || $durum == "iptal"){
                echo "OK";
                exit;
            }
         */

    $ci = &get_instance();
    if ($data['status'] == 'success') { ## Ödeme Onaylandı
      $get_order = $ci->db->from("orders")->where("order_no", $data['merchant_oid'])->get()->row();
      $company = $ci->db->from("company_info")->where("company_id", $get_order->company_id)->get()->row();
      $upd_order = $ci->db->where("id", $get_order->id)->update("orders", array(
        "status" => 1
      ));

      if ($upd_order) {
        $credits = array_reduce(json_decode($get_order->details, 1), function ($total, $item) {
          $total += ($item["credit_qty"] * $item["package_qty"]);
          return $total;
        });

        $ci->db->where("company_id", $company->company_id)->update("company_info", array(
          "credit" => ($company->credit + $credits)
        ));
      }
      return true;
      ## BURADA YAPILMASI GEREKENLER
      ## 1) Siparişi onaylayın.
      ## 2) Eğer müşterinize mesaj / SMS / e-posta gibi bilgilendirme yapacaksanız bu aşamada yapmalısınız.
      ## 3) 1. ADIM'da gönderilen payment_amount sipariş tutarı taksitli alışveriş yapılması durumunda
      ## değişebilir. Güncel tutarı $post['total_amount'] değerinden alarak muhasebe işlemlerinizde kullanabilirsiniz.

    } else { ## Ödemeye Onay Verilmedi
      return false;
      ## BURADA YAPILMASI GEREKENLER
      ## 1) Siparişi iptal edin.
      ## 2) Eğer ödemenin onaylanmama sebebini kayıt edecekseniz aşağıdaki değerleri kullanabilirsiniz.
      ## $post['failed_reason_code'] - başarısız hata kodu
      ## $post['failed_reason_msg'] - başarısız hata mesajı
      $get_order = $ci->db->from("orders")->where("order_no", $data['merchant_oid'])->get()->row();
      $upd_order = $ci->db->where("id", $get_order->id)->update("orders", array(
        "failed_reason_code" => $data['failed_reason_code'],
        "failed_reason_msg" => $data['failed_reason_msg']
      ));
    }
    echo "OK";  //sadece paytr okuyacağı için, front tarafa birşey döndürmeye gerek yok
    exit;
  }
}

/* End of file Paytr.php */
/* Location: ./application/libraries/Paytr.php */