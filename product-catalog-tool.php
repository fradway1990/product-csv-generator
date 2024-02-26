<?php
/**
 * Product Catalog tool
 *
 * @author Frank Collins
 *
 * Plugin Name:       Product Catalog Tool
 * Description:       Download Product Catalog CSV
 * Version:           1.0
 * Author:            Frank Collins
 */

class Product_Catalog
{

    public function __construct()
    {
        add_action('init', array( $this, 'init'));
        add_action('init', array($this, 'pc_endpoint'));
        add_action('wp_loaded', array($this,'download_csv'));
        add_action('admin_menu', array($this, 'product_catalog_settings_menu'));
        add_action('admin_init', array($this, 'product_catalog_settings'));
    }
    
    public function init()
    {
        
        $allowed_roles = get_option('product_catalog_allowed_roles');
        $is_allowed = false;
        if(is_user_logged_in() ) {
            $current_user = wp_get_current_user();
            foreach($allowed_roles as $role) {
                if(in_array($role, $current_user->roles)) {
                    $is_allowed = true;
                    break;
                }
            }

            if($is_allowed ) {
                
                add_filter('woocommerce_account_menu_items', array($this, 'add_product_catalog_tab'));
                add_action('woocommerce_account_product-catalog_endpoint', array($this, 'pc_tab_content'));
            }
        }
    }

    public function add_product_catalog_tab($menu_items)
    {
        $menu_items['product-catalog'] = __('Download Product Catalog', 'inverse-paradox');
        return $menu_items;
    }

    public function pc_tab_content()
    {
        $account_link = get_permalink(wc_get_page_id('myaccount'));
        $account_link = rtrim($account_link, "/");
        echo "<a href='{$account_link}/product-catalog?download-product'>Download CSV</a>";
    }

    
    public function pc_endpoint()
    {

        add_rewrite_endpoint('product-catalog', EP_ROOT | EP_PAGES);

    }

    private function get_products()
    {
        global $wpdb;
        $query = new WC_Product_Query(
            array(
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'stock_status'   => array( 'instock', 'outofstock' ),
            ) 
        );
        $products = $query->get_products();
        $product_info = array();
        foreach ( $products as $product) {
            $product_name     = $product->get_name();
            $product_price    = $product->get_price();
            $product_quantity = $product->get_stock_quantity();
            $product_sku      = $product->get_sku();
            $product_info[] = array(
            'name' => $product_name,
            'price' => $product_price,
            'stock_quantity' => $product_quantity,
            'sku' => $product_sku,
            );
        }
        return $product_info;
    }

    public function generate_csv()
    {
        $products = self::get_products();
        if (!empty($products) ) {
            $csv = array();
            $csv[] = array('Product Name', 'Price', 'Stock Quantity', 'SKU');

            foreach ($products as $product) {
                $csv[] = array(
                 $product['name'],
                 $product['price'],
                 $product['stock_quantity'],
                 $product['sku']
                );
            }
            $current_date_time = date('mdY-His');
            $login = '';
            if (is_user_logged_in()) {
                $current_user = wp_get_current_user();
                $login = $current_user->user_login;
            }
            $file_name = 'ebox-solutions-'. $login .'-'. $current_date_time .'.csv';
            $csv_file = fopen(__DIR__ . '/' . $file_name, 'w');

            foreach ($csv as $csv_row) {
                fputcsv($csv_file, $csv_row);
            }

            fclose($csv_file);
            return $file_name;
        } else {
            return false;
        }
    }

    public function download_csv()
    {
        $allowed_roles = get_option('product_catalog_allowed_roles');
        $is_allowed = false;
        if(is_user_logged_in()) {
            $current_user = wp_get_current_user();
            foreach($allowed_roles as $role) {
                if(in_array($role, $current_user->roles)) {
                    $is_allowed = true;
                    break;
                }
            }
        }
        if (isset($_GET['download-product']) && $is_allowed) {
            // Generate CSV
            $file_name = $this->generate_csv();
            if(! $file_name ) {
                return false;
            }
            $file_directory = plugin_dir_path(__FILE__);
            $file_path = $file_directory . '/' . $file_name;

            if (file_exists($file_path)) {
                header("Cache-control: public");
                header("Content-Description: File Transfer");
                header("Content-Disposition: attachment; filename={$file_name}");
                header("Content-Type: text/csv");
                header("Content-Transfer-Encoding: utf-8");
                readfile($file_path);
                unlink($file_path);
                exit;
            }

        }
    }

    public function product_catalog_settings_menu()
    {
        add_menu_page(
            __('Product Catalog Download Tool Settings', 'inverse-paradox'),
            __('Product Catalog Download Tool Settings', 'inverse-paradox'),
            'manage_options',
            'product_catalog_download_tool_settings',
            array($this,'product_catalog_settings_template'),
            null,
        );
    }

    public function product_catalog_settings_template()
    {
        ?>
        <div class="wrap">
        <h2>Product Catalog Download Tool Settings</h2>
        <form method="post" action="options.php">
            <?php
            //Security Fields
            settings_fields('product_catalog_download_tool_settings');

            // Output settings section
            do_settings_sections('product_catalog_download_tool_settings');
            submit_button();
            ?>
        </form>
        </div>
        <?php
    }

    function product_catalog_settings()
    {
        // Role Selection Fields
        add_settings_section(
            'catalog_role_section',
            'Role Selection',
            array($this, 'woocommerce_api_keys_callback'),
            'product_catalog_download_tool_settings'
        );

        register_setting(
            'product_catalog_download_tool_settings',
            'product_catalog_allowed_roles',
            array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_checkboxes'),
            'default' => array(),
            )
        );

        add_settings_field(
            'product_catalog_allowed_roles',
            __('Allowed Roles', 'inverse-paradox'),
            array($this, 'catalog_allowed_roles_callback'),
            'product_catalog_download_tool_settings',
            'catalog_role_section'
        );
    }

    function woocommerce_api_keys_callback()
    {
        return true;
    }

    function catalog_allowed_roles_callback()
    {
        global $wp_roles;
        $roles = $wp_roles->roles;
        $allowed_roles = get_option('product_catalog_allowed_roles');
        foreach( $roles as $role_key => $role_meta ) :
            $checked =  in_array($role_key, $allowed_roles) ? 'checked' : '';
            ?>
            <div style="margin-bottom: 5px;">
                <input id="role-select-<?php echo esc_html($role_key)?>"
                type="checkbox"
                name="product_catalog_allowed_roles[]"
                value="<?php echo esc_html($role_key); ?>"
            <?php echo $checked; ?>>
                <label for="role-select-<?php echo esc_html($role_key)?>"><?php echo esc_html($role_meta['name']); ?></label>
            </div>
            <?php
        endforeach;
    }

    function sanitize_checkboxes($input)
    {
        return $input;
    }
}

$catalog_instance = new Product_Catalog();
