<?php
/**
 * Created by PhpStorm.
 * User: vladislav
 * Date: 14.10.2017
 * Time: 2:20
 */

require_once( 'settings.php' );

require_once('../api/woocommerce-api.php');

require_once( '../parser/parser.php' );
function db(){
    $db_id = mysql_connect(db_host, db_username, db_password)
    or die('Не удалось соединиться: ' . mysql_error());
    mysql_select_db('updateproducts')
    or die('Не удалось выбрать базу данных');
}
db();

/**
 * Created by PhpStorm.
 * User: vlad-
 * Date: 27.07.2017
 * Time: 10:50
 */
function update_product_list()
{
    mysql_query('UPDATE `settings` SET `value`=1 WHERE `title`="status_step_updating"');

    mysql_query('TRUNCATE TABLE `errors`');
    mysql_query('ALTER TABLE `errors` AUTO_INCREMENT=0');
    mysql_query('TRUNCATE TABLE `products_list`');
    mysql_query('ALTER TABLE `products_list` AUTO_INCREMENT=0');

    $options = array(
        'debug' => false,
        'return_as_array' => true,
        'validate_url' => false,
        'timeout' => 300,
        'ssl_verify' => false,
    );

    try {

        $client = new WC_API_Client(api_host, api_key_ck, api_key_cs, $options);
        $quantiti_products = $client->products->get_count()['count'];

        mysql_query('UPDATE `updateproducts`.`settings` SET `value` = ' . $quantiti_products . ' WHERE `settings`.`id` = 2');
        for ($step = 0; $step < ceil($quantiti_products / 100); $step++) {
            $arrary_of_products = $client->products->get('', array('fields' => 'id,permalink,meta', 'filter[limit]' => 100, 'filter[offset]' => $step * 100))['products'];
            for ($num_products_in_stack = 0; $num_products_in_stack < count($arrary_of_products); $num_products_in_stack++) {
                $product_data = $arrary_of_products[$num_products_in_stack];
                mysql_query('INSERT INTO `products_list`(`product_id`, `parsing_url`, `product_url`, `date_upload_product_in_list`, `status`) VALUES (' . $product_data['id'] . ',"' . $product_data['meta']['provider_url'] . '","' . $product_data['permalink'] . '",' . time() . ',0)');
                mysql_query('UPDATE `settings` SET `value` = ' . time() . ' WHERE `title` = "last_update"');
                continue_update();
            }
        }


    } catch (WC_API_Client_Exception $e) {

        echo $e->getMessage() . PHP_EOL;
        echo $e->getCode() . PHP_EOL;

        if ($e instanceof WC_API_Client_HTTP_Exception) {

            print_r($e->get_request());
            print_r($e->get_response());
        }
    }
}
/**
 * Created by PhpStorm.
 * User: vlad-
 * Date: 27.07.2017
 * Time: 18:25
 */
function update_product_information(){

    mysql_query('UPDATE `settings` SET `value`=2 WHERE `title`="status_step_updating"');

    while(mysql_num_rows( $query=mysql_query('SELECT `id`,`parsing_url` FROM `products_list` WHERE `status`=0  ORDER BY ID ASC LIMIT 1'))) {
        $data = mysql_fetch_object($query);
        $content = new simple_html_dom();
        $content->load(get($data->parsing_url));
        $pars_data = pars($data->parsing_url,$content,true);
        if (!is_array($pars_data)) {
            if($pars_data==470 || $pars_data==471 ){
                remove_product($data->id);
            }
           mysql_query('UPDATE `products_list` SET `product_quantiti` = 0,`product_price` = 0,`date_update` = '.time().',`status` = 500 WHERE `id` = '.$data->id);
        }else{
            mysql_query('UPDATE `products_list` SET `product_quantiti` = ' . $pars_data['stock_quantity'] . ',`product_price` = ' . $pars_data['regular_price'] . ',`date_update` = ' . time() . ',`status` = "1"  WHERE `id` = ' . $data->id);
        }
        mysql_query('UPDATE `settings` SET `value` = ' . time() . ' WHERE `title` = "last_update"');
        unset($data,$content,$pars_data);
        continue_update();
    }
    return;
}

/**
 * Created by PhpStorm.
 * User: vlad-
 * Date: 27.07.2017
 * Time: 18:25
 */



function remove_product($product_id){
    $options = array(
        'debug' => false,
        'return_as_array' => true,
        'validate_url' => false,
        'timeout' => 300,
        'ssl_verify' => false,
    );

    try {

        $client = new WC_API_Client(api_host, api_key_ck, api_key_cs, $options);

        $client->products->delete( $product_id);

    } catch (WC_API_Client_Exception $e) {

        echo $e->getMessage() . PHP_EOL;
        echo $e->getCode() . PHP_EOL;

        if ($e instanceof WC_API_Client_HTTP_Exception) {

            print_r($e->get_request());
            print_r($e->get_response());
        }
    }

}
function upload_products(){
    mysql_query('UPDATE `settings` SET `value`=3 WHERE `title`="status_step_updating"');

    $options = array(
        'debug' => false,
        'return_as_array' => true,
        'validate_url' => false,
        'timeout' => 300,
        'ssl_verify' => false,
    );

    try {

        $client = new WC_API_Client(api_host, api_key_ck, api_key_cs, $options);

        while(mysql_num_rows( mysql_query('SELECT `id` FROM `products_list` WHERE `date_upload`=0  ORDER BY ID ASC LIMIT 1'))){
            $data = mysql_fetch_object(
                mysql_query('SELECT `id`, `product_id`, `product_quantiti`, `product_price`,`status` FROM `products_list` WHERE `date_upload`=0 ORDER BY ID ASC LIMIT 1')
            );

            $client->products->update( $data->product_id, array('sale_price' => '','managing_stock'   => true , 'stock_quantity' => $data->product_quantiti, 'regular_price' => $data->product_price ));
            if($data->status==500 || $data->status==404){
                mysql_query('UPDATE `products_list` SET `date_upload` = ' . time() . ' WHERE `id`='.$data->id);
            }else{
                mysql_query('UPDATE `products_list` SET `status`=2, `date_upload` = ' . time() . ' WHERE `id`='.$data->id);
            }
            mysql_query('UPDATE `settings` SET `value` = ' . time() . ' WHERE `title` = "last_update"');

            continue_update();
        }

    } catch (WC_API_Client_Exception $e) {

        echo $e->getMessage() . PHP_EOL;
        echo $e->getCode() . PHP_EOL;

        if ($e instanceof WC_API_Client_HTTP_Exception) {

            print_r($e->get_request());
            print_r($e->get_response());
        }
    }

}

function continue_update(){
    $continue_update = mysql_fetch_array(mysql_query("SELECT `value` FROM `settings` WHERE `title`='continue_update'"))[0];
    if($continue_update==1){
        return;
    }elseif ($continue_update==0){
        exit;
    }elseif ($continue_update==2){
        sleep(1);
        continue_update();
    }
}

function table_in_array($mysql_query){
    $rs=mysql_query($mysql_query);
    $table = array();
    $schet=0;
    while($row = mysql_fetch_assoc($rs)) {
        $strROW = array();
        foreach ($row as $key => $value){
            $strROW[$key] = $value;
        }
        $table[$schet] = $strROW;
        $schet++;
    }
    return $table;
}
function send_email($message){
    $to      = 'vlad-sys-1998@yandex.ru';
    $subject = 'Обновление цены и количества товаров vs db';
    $headers = 'From: updates-on-mnogosveta.su' . "\r\n" .
        'Reply-To: webmaster@example.com' . "\r\n" .
        'X-Mailer: PHP/' . phpversion();
    mail($to, $subject, $message, $headers);
}
function send($a){
    print_r(json_encode($a));
}
function write_error($code,$message,$url)
{
    if ($url != '') {
        if (preg_match('/magia-sveta.ru/', $url)) {
            $shop = 'magia-sveta';
        } elseif (preg_match('/antares-svet.ru/', $url)) {
            $shop = 'antares-svet';
        } elseif (preg_match('/electra.ru/', $url)) {
            $shop = 'electra';
        }
    }

    mysql_query('INSERT INTO `errors_log` (`time`, `error_code`, `data`, `url`,`shop`) VALUES (' . time() . ', ' . $code . ', "' . mysql_real_escape_string($message) . '", "' . mysql_real_escape_string($url) . '", "' . $shop . '")');
}
?>