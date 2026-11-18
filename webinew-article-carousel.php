<?php
/**
 * Plugin Name: کاروسل محصولات انتخابی در مقالات (نسخه حرفه‌ای)
 * Plugin URI: https://webinew.com
 * Description: نمایش حرفه‌ای محصولات و دسته‌بندی‌های ووکامرس در لابه‌لای مقالات با ارث‌بری از گلوبال استایل‌های وودمارت و المنتور، کش سرعت بالا و آپدیت مستقیم از گیت‌هاب.
 * Version: 2.1.0
 * Author: احسان قسیمی - تیم وبینیو (Webinyo)
 * Author URI: https://webinew.com
 */

if (!defined('ABSPATH')) {
    exit;
}

class Custom_Article_Product_Carousel {

    private $option_name = 'capc_carousels_data';
    private $github_repo = 'EhsanGhasimi/webinew-article-carousel';
    private $plugin_slug;
    private $version = '2.1.0';

    public function __construct() {
        $this->plugin_slug = plugin_basename(__FILE__);

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets'], 25);

        add_action('wp_ajax_capc_search_items', [$this, 'ajax_search_items']);
        add_action('wp_ajax_capc_save_carousel', [$this, 'ajax_save_carousel']);
        add_action('wp_ajax_capc_delete_carousel', [$this, 'ajax_delete_carousel']);
        add_action('wp_ajax_capc_clear_cache', [$this, 'ajax_clear_cache']);

        // پاکسازی خودکار کش کاروسل‌ها هنگام آپدیت شدن هر محصول
        add_action('woocommerce_update_product', [$this, 'flush_all_carousel_caches']);

        add_shortcode('article_products', [$this, 'render_shortcode']);

        // هوک‌های آپدیت خودکار از GitHub
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_github_update']);
        add_filter('plugins_api', [$this, 'github_plugin_info'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_github_folder_name'], 10, 4);
    }

    public function check_github_update($transient) {
        if (empty($transient->checked) || strpos($this->github_repo, 'YOUR-GITHUB-USERNAME') !== false) {
            return $transient;
        }
        $release = $this->get_latest_github_release();
        if (!$release) return $transient;

        $latest_version = ltrim($release->tag_name, 'vV');
        if (version_compare($this->version, $latest_version, '<')) {
            $plugin_data = new stdClass();
            $plugin_data->slug = dirname($this->plugin_slug);
            $plugin_data->plugin = $this->plugin_slug;
            $plugin_data->new_version = $latest_version;
            $plugin_data->url = $release->html_url;
            $plugin_data->package = $release->zipball_url;
            $transient->response[$this->plugin_slug] = $plugin_data;
        }
        return $transient;
    }

    public function github_plugin_info($res, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== dirname($this->plugin_slug)) {
            return $res;
        }
        $release = $this->get_latest_github_release();
        if (!$release) return $res;

        $res = new stdClass();
        $res->name = 'کاروسل محصولات انتخابی در مقالات';
        $res->slug = dirname($this->plugin_slug);
        $res->version = ltrim($release->tag_name, 'vV');
        $res->author = '<a href="https://webinew.com">احسان قسیمی - تیم وبینیو</a>';
        $res->homepage = 'https://webinew.com';
        $res->download_link = $release->zipball_url;
        $res->sections = [
            'description' => 'افزونه اختصاصی نمایش کاروسل محصولات در لابه‌لای مقالات.',
            'changelog'   => nl2br(esc_html($release->body ?: 'بهبود رابط کاربری و بهینه‌سازی سرعت.'))
        ];
        return $res;
    }

    public function fix_github_folder_name($source, $remote_source, $upgrader, $hook_extra = null) {
        global $wp_filesystem;
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
            return $source;
        }
        $corrected_source = trailingslashit($remote_source) . dirname($this->plugin_slug) . '/';
        return $wp_filesystem->move($source, $corrected_source) ? $corrected_source : $source;
    }

    private function get_latest_github_release() {
        $cache_key = 'capc_gh_release_' . md5($this->github_repo);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $response = wp_remote_get("https://api.github.com/repos/{$this->github_repo}/releases/latest", [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'WordPress-CAPC-Updater']
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return false;
        $body = json_decode(wp_remote_retrieve_body($response));
        if (empty($body->tag_name)) return false;

        set_transient($cache_key, $body, HOUR_IN_SECONDS);
        return $body;
    }

    public function add_admin_menu() {
        add_menu_page(
            'کاروسل محصولات مقاله',
            'کاروسل مقالات',
            'manage_options',
            'capc-manager',
            [$this, 'render_admin_page'],
            'dashicons-slides',
            56
        );
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_capc-manager') {
            return;
        }
        // ثبت صحیح استایل تا وردپرس حتماً آن را در هدر چاپ کند
        wp_register_style('capc-admin-style', false);
        wp_enqueue_style('capc-admin-style');
        wp_add_inline_style('capc-admin-style', '
            .capc-admin-wrapper {
                max-width: 1180px;
                margin: 24px 0 24px 20px;
                background: #ffffff;
                border: 1px solid #e2e8f0;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
                font-family: inherit;
                color: #1e293b;
            }
            .capc-topbar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 20px 28px;
                background: #0f172a;
                color: #ffffff;
                border-bottom: 3px solid #EF3A4F;
            }
            .capc-topbar h1 {
                margin: 0;
                font-size: 18px;
                font-weight: 700;
                color: #ffffff;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .capc-version-pill {
                background: rgba(239, 58, 79, 0.2);
                color: #fda4af;
                border: 1px solid rgba(239, 58, 79, 0.4);
                padding: 2px 8px;
                font-size: 11px;
                font-family: monospace;
            }
            .capc-dev-credit {
                font-size: 12px;
                color: #cbd5e1;
                background: rgba(255, 255, 255, 0.06);
                padding: 7px 14px;
                border: 1px solid rgba(255, 255, 255, 0.12);
            }
            .capc-dev-credit a {
                color: #EF3A4F;
                text-decoration: none;
                font-weight: 700;
            }
            .capc-tabs {
                display: flex;
                background: #f8fafc;
                border-bottom: 1px solid #e2e8f0;
                padding: 0 28px;
                gap: 6px;
            }
            .capc-tab {
                padding: 14px 22px;
                cursor: pointer;
                font-weight: 600;
                font-size: 13.5px;
                color: #64748b;
                border-bottom: 3px solid transparent;
                margin-bottom: -1px;
                transition: all 0.2s ease;
            }
            .capc-tab:hover {
                color: #0f172a;
            }
            .capc-tab.active {
                color: #EF3A4F;
                border-bottom-color: #EF3A4F;
                background: #ffffff;
            }
            .capc-body {
                padding: 28px;
            }
            .capc-settings-bar {
                display: grid;
                grid-template-columns: 2fr 1fr 1fr 1fr;
                gap: 16px;
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                padding: 18px;
                margin-bottom: 22px;
                align-items: end;
            }
            .capc-field label {
                display: block;
                font-size: 12.5px;
                font-weight: 700;
                color: #334155;
                margin-bottom: 6px;
            }
            .capc-field input[type="text"],
            .capc-field input[type="number"],
            .capc-field select {
                width: 100%;
                height: 38px;
                border: 1px solid #cbd5e1;
                padding: 0 12px;
                background: #ffffff;
                color: #0f172a;
                box-shadow: none;
            }
            .capc-field input:focus,
            .capc-field select:focus {
                border-color: #EF3A4F;
                outline: none;
            }
            .capc-checkbox-inline {
                display: flex;
                align-items: center;
                gap: 8px;
                height: 38px;
                background: #ffffff;
                border: 1px solid #cbd5e1;
                padding: 0 12px;
                cursor: pointer;
                font-size: 12.5px;
                font-weight: 600;
            }
            .capc-selector-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 22px;
            }
            .capc-panel {
                border: 1px solid #e2e8f0;
                background: #ffffff;
                display: flex;
                flex-direction: column;
            }
            .capc-panel-head {
                padding: 14px 16px;
                background: #f8fafc;
                border-bottom: 1px solid #e2e8f0;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .capc-panel-head strong {
                font-size: 13.5px;
                color: #0f172a;
            }
            .capc-count-pill {
                background: #EF3A4F;
                color: #ffffff;
                font-size: 11px;
                padding: 2px 8px;
                font-weight: 700;
            }
            .capc-search-box {
                padding: 10px 14px;
                border-bottom: 1px solid #f1f5f9;
            }
            .capc-search-box input {
                width: 100%;
                height: 36px;
                border: 1px solid #cbd5e1;
                padding: 0 10px;
            }
            .capc-selected-tags {
                padding: 8px 14px;
                background: #fff1f2;
                border-bottom: 1px solid #fecdd3;
                display: none;
                flex-wrap: wrap;
                gap: 6px;
            }
            .capc-tag {
                background: #EF3A4F;
                color: #fff;
                font-size: 11.5px;
                padding: 3px 8px;
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }
            .capc-tag span {
                cursor: pointer;
                font-weight: bold;
            }
            .capc-checklist {
                height: 290px;
                overflow-y: auto;
                padding: 6px 14px;
            }
            .capc-check-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 9px 6px;
                border-bottom: 1px solid #f1f5f9;
                cursor: pointer;
                transition: background 0.15s;
                margin: 0;
            }
            .capc-check-row:hover {
                background: #f8fafc;
            }
            .capc-check-row-right {
                display: flex;
                align-items: center;
                gap: 9px;
                font-size: 13px;
                color: #1e293b;
            }
            .capc-check-row-meta {
                font-size: 11px;
                color: #64748b;
                background: #f1f5f9;
                padding: 2px 7px;
                font-family: monospace;
            }
            .capc-footer-actions {
                margin-top: 24px;
                padding-top: 18px;
                border-top: 1px solid #e2e8f0;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
            .capc-btn-primary {
                background: #EF3A4F;
                color: #ffffff;
                border: none;
                padding: 12px 28px;
                font-size: 14px;
                font-weight: 700;
                cursor: pointer;
                transition: background 0.2s;
            }
            .capc-btn-primary:hover {
                background: #d62839;
            }
            .capc-btn-secondary {
                background: #f1f5f9;
                color: #334155;
                border: 1px solid #cbd5e1;
                padding: 9px 16px;
                font-size: 12.5px;
                font-weight: 600;
                cursor: pointer;
            }
            .capc-btn-secondary:hover {
                background: #e2e8f0;
            }
            .capc-table {
                width: 100%;
                border-collapse: collapse;
                border: 1px solid #e2e8f0;
            }
            .capc-table th {
                background: #f8fafc;
                text-align: right;
                padding: 12px 14px;
                font-size: 13px;
                border-bottom: 2px solid #e2e8f0;
                color: #0f172a;
            }
            .capc-table td {
                padding: 12px 14px;
                border-bottom: 1px solid #f1f5f9;
                font-size: 13px;
                vertical-align: top;
            }
            .capc-shortcode-box {
                background: #f1f5f9;
                border: 1px solid #cbd5e1;
                padding: 5px 10px;
                font-family: monospace;
                font-size: 12.5px;
                color: #0f172a;
                cursor: pointer;
                display: inline-block;
            }
            .capc-shortcode-box:hover {
                border-color: #EF3A4F;
                color: #EF3A4F;
            }
            @media (max-width: 960px) {
                .capc-settings-bar, .capc-selector-grid { grid-template-columns: 1fr; }
            }
        ');
    }

    public function enqueue_frontend_assets() {
        if (wp_script_is('swiper', 'registered')) {
            wp_enqueue_script('swiper');
        } elseif (defined('ELEMENTOR_ASSETS_URL')) {
            wp_enqueue_script('swiper', ELEMENTOR_ASSETS_URL . 'lib/swiper/v8/swiper.min.js', [], '8.4.5', true);
            wp_enqueue_style('swiper', ELEMENTOR_ASSETS_URL . 'lib/swiper/v8/css/swiper.min.css', [], '8.4.5');
        }

        if (wp_style_is('swiper', 'registered')) {
            wp_enqueue_style('swiper');
        } elseif (wp_style_is('e-swiper', 'registered')) {
            wp_enqueue_style('e-swiper');
        }

        wp_register_style('capc-pro-style', false);
        wp_enqueue_style('capc-pro-style');
        wp_add_inline_style('capc-pro-style', '
            .capc-carousel-wrapper {
                --capc-primary: var(--wd-primary-color, var(--e-global-color-primary, #d81b60));
                --capc-font-text: var(--wd-text-font, var(--e-global-typography-text-font-family, inherit));
                --capc-font-title: var(--wd-title-font, var(--e-global-typography-primary-font-family, inherit));
                --capc-radius: var(--wd-brd-radius, 12px);

                position: relative;
                margin: 34px 0;
                padding: 20px 18px 34px;
                background: linear-gradient(180deg, #ffffff 0%, #fcf8fa 100%);
                border: 1px solid rgba(0, 0, 0, 0.07);
                border-radius: calc(var(--capc-radius) + 4px);
                box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.06);
                font-family: var(--capc-font-text);
                overflow: hidden;
            }
            .capc-carousel-wrapper .swiper { padding: 4px 2px 10px; }
            .capc-carousel-wrapper .swiper-wrapper {
                display: flex !important;
                flex-wrap: nowrap !important;
                align-items: stretch !important;
                margin: 0 !important;
                padding: 0 !important;
                gap: 0 !important;
            }
            .capc-carousel-wrapper .swiper-slide {
                height: auto !important;
                display: flex !important;
                flex-direction: column !important;
            }
            .capc-carousel-wrapper .swiper-slide > .product-grid-item,
            .capc-carousel-wrapper .swiper-slide > li.product,
            .capc-carousel-wrapper .swiper-slide > div.product {
                width: 100% !important;
                max-width: 100% !important;
                height: 100% !important;
                margin: 0 !important;
                padding: 12px !important;
                background: #ffffff;
                border: 1px solid rgba(0, 0, 0, 0.06);
                border-radius: var(--capc-radius);
                box-shadow: 0 3px 12px rgba(0, 0, 0, 0.03);
                transition: transform 0.3s cubic-bezier(0.25, 0.8, 0.25, 1), box-shadow 0.3s ease, border-color 0.3s ease;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                list-style: none;
            }
            .capc-carousel-wrapper .swiper-slide > .product-grid-item:hover,
            .capc-carousel-wrapper .swiper-slide > li.product:hover,
            .capc-carousel-wrapper .swiper-slide > div.product:hover {
                transform: translateY(-4px);
                box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.12);
                border-color: var(--capc-primary);
            }
            .capc-carousel-wrapper .product-element-top,
            .capc-carousel-wrapper .woocommerce-loop-product__link img {
                border-radius: calc(var(--capc-radius) - 4px);
                overflow: hidden;
            }
            .capc-carousel-wrapper .wd-entities-title,
            .capc-carousel-wrapper .woocommerce-loop-product__title {
                font-family: var(--capc-font-title) !important;
                font-size: 14.5px !important;
                font-weight: 700 !important;
                line-height: 1.55 !important;
                min-height: 45px;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
                margin-bottom: 8px !important;
            }
            .capc-carousel-wrapper .price {
                font-family: var(--capc-font-text) !important;
                font-weight: 700 !important;
                color: var(--capc-primary) !important;
                margin-bottom: 10px !important;
            }
            .capc-carousel-wrapper .wd-add-btn > a,
            .capc-carousel-wrapper a.button.add_to_cart_button {
                font-family: var(--capc-font-text) !important;
                border-radius: calc(var(--capc-radius) - 4px) !important;
                transition: all 0.25s ease !important;
                width: 100%;
                justify-content: center;
            }
            .capc-carousel-wrapper .swiper-slide:hover .wd-add-btn > a,
            .capc-carousel-wrapper .swiper-slide:hover a.button.add_to_cart_button {
                background-color: var(--capc-primary) !important;
                color: #ffffff !important;
            }
            .capc-nav-btn {
                position: absolute;
                top: 46%;
                transform: translateY(-50%);
                z-index: 20;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.92);
                backdrop-filter: blur(6px);
                -webkit-backdrop-filter: blur(6px);
                color: #1f2937;
                border: 1px solid rgba(0, 0, 0, 0.08);
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
                transition: all 0.25s ease;
                user-select: none;
            }
            .capc-nav-btn:hover {
                background: var(--capc-primary);
                color: #ffffff;
                border-color: var(--capc-primary);
                transform: translateY(-50%) scale(1.08);
            }
            .capc-nav-btn svg { width: 18px; height: 18px; stroke: currentColor; stroke-width: 2.3; fill: none; }
            .capc-prev { right: 10px; }
            .capc-next { left: 10px; }
            .capc-pagination {
                position: absolute !important;
                bottom: 8px !important;
                left: 0 !important;
                right: 0 !important;
                text-align: center;
                z-index: 15;
            }
            .capc-pagination .swiper-pagination-bullet {
                width: 7px;
                height: 7px;
                background: #cbd5e1;
                opacity: 1;
                border-radius: 99px;
                transition: all 0.3s ease;
                margin: 0 4px !important;
            }
            .capc-pagination .swiper-pagination-bullet-active {
                width: 22px;
                background: var(--capc-primary);
            }
            @media (max-width: 640px) {
                .capc-carousel-wrapper { padding: 14px 10px 30px; margin: 24px 0; }
                .capc-nav-btn { width: 34px; height: 34px; }
                .capc-prev { right: 6px; }
                .capc-next { left: 6px; }
            }
        ');
    }

    public function render_admin_page() {
        $carousels = get_option($this->option_name, []);
        ?>
        <div class="wrap">
            <div class="capc-admin-wrapper">
                <!-- هدر مدرن پنل -->
                <div class="capc-topbar">
                    <h1>
                        مدیریت کاروسل محصولات در مقالات
                        <span class="capc-version-pill">v<?php echo esc_html($this->version); ?></span>
                    </h1>
                    <div class="capc-dev-credit">
                        توسعه‌دهنده: <strong>احسان قسیمی</strong> | تیم <a href="https://webinew.com" target="_blank">وبینیو (Webinyo)</a>
                    </div>
                </div>

                <!-- تب‌ها -->
                <div class="capc-tabs">
                    <div class="capc-tab active" onclick="switchTab('create', this)">+ ساخت شورت‌کد جدید</div>
                    <div class="capc-tab" onclick="switchTab('report', this)">لیست شورت‌کدها و گزارش صفحات (<?php echo count($carousels); ?>)</div>
                </div>

                <div class="capc-body">
                    <!-- تب ساخت کاروسل -->
                    <div id="tab-create" class="capc-tab-content">
                        <!-- نوار تنظیمات کارایی و محدودیت لود -->
                        <div class="capc-settings-bar">
                            <div class="capc-field">
                                <label>عنوان کاروسل (جهت شناسایی در پنل):</label>
                                <input type="text" id="capc_title" placeholder="مثلاً: باکس‌های هدیه پیشنهادی مقاله روز زن...">
                            </div>
                            <div class="capc-field">
                                <label>حداکثر تعداد محصول در کاروسل:</label>
                                <input type="number" id="capc_limit" value="8" min="3" max="20" title="برای حفظ سرعت لود صفحه، عدد بین ۶ تا ۱۰ پیشنهاد می‌شود">
                            </div>
                            <div class="capc-field">
                                <label>مرتب‌سازی محصولات:</label>
                                <select id="capc_orderby">
                                    <option value="date">جدیدترین محصولات</option>
                                    <option value="popularity">پرفروش‌ترین‌ها</option>
                                    <option value="rand">تصادفی (تنوع در نمایش)</option>
                                    <option value="price">ارزان‌ترین به گران‌ترین</option>
                                    <option value="price-desc">گران‌ترین به ارزان‌ترین</option>
                                </select>
                            </div>
                            <div class="capc-field">
                                <label>وضعیت موجودی:</label>
                                <label class="capc-checkbox-inline">
                                    <input type="checkbox" id="capc_instock" checked>
                                    فقط کالاهای موجود
                                </label>
                            </div>
                        </div>

                        <!-- باکس دو ستونه انتخاب دسته‌بندی و محصولات -->
                        <div class="capc-selector-grid">
                            <!-- ستون دسته‌بندی‌ها -->
                            <div class="capc-panel">
                                <div class="capc-panel-head">
                                    <strong>۱. انتخاب دسته‌بندی‌های ووکامرس</strong>
                                    <span class="capc-count-pill" id="count_cats">۰ انتخاب</span>
                                </div>
                                <div class="capc-search-box">
                                    <input type="text" placeholder="جستجوی نام دسته‌بندی..." onkeyup="loadItems('category', this.value)">
                                </div>
                                <div class="capc-selected-tags" id="tags_cats"></div>
                                <div class="capc-checklist" id="list_categories">در حال بارگذاری...</div>
                            </div>

                            <!-- ستون محصولات -->
                            <div class="capc-panel">
                                <div class="capc-panel-head">
                                    <strong>۲. انتخاب محصولات خاص (دستی)</strong>
                                    <span class="capc-count-pill" id="count_prods">۰ انتخاب</span>
                                </div>
                                <div class="capc-search-box">
                                    <input type="text" placeholder="جستجوی نام محصول..." onkeyup="loadItems('product', this.value)">
                                </div>
                                <div class="capc-selected-tags" id="tags_prods"></div>
                                <div class="capc-checklist" id="list_products">در حال بارگذاری...</div>
                            </div>
                        </div>

                        <div class="capc-footer-actions">
                            <div>
                                <button type="button" class="capc-btn-primary" onclick="saveCarousel()">ذخیره و تولید شورت‌کد</button>
                                <span id="save_status" style="margin-right: 15px; font-weight: bold; color: #16a34a;"></span>
                            </div>
                            <div style="font-size: 12px; color: #64748b;">
                                * خروجی کاروسل جهت حفظ سرعت لود صفحات، به صورت هوشمند کش (Cache) می‌شود.
                            </div>
                        </div>
                    </div>

                    <!-- تب گزارش‌گیری -->
                    <div id="tab-report" class="capc-tab-content" style="display:none;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                            <p style="margin:0; color:#475569;">برای کپی کردن شورت‌کد، روی باکس کد کلیک کنید و آن را در هر بخش از متن مقاله قرار دهید.</p>
                            <button type="button" class="capc-btn-secondary" onclick="clearAllCaches()">پاکسازی کش کاروسل‌ها</button>
                        </div>

                        <table class="capc-table">
                            <thead>
                                <tr>
                                    <th>عنوان کاروسل</th>
                                    <th>شورت‌کد (کلیک برای کپی)</th>
                                    <th>تنظیمات و محدودیت</th>
                                    <th>مقالات و صفحاتی که این شورت‌کد در آن‌ها قرار دارد</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($carousels)): ?>
                                    <tr><td colspan="5" style="text-align:center; padding:30px; color:#64748b;">هنوز هیچ شورت‌کدی ساخته نشده است.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($carousels as $id => $item):
                                        $shortcode_str = '[article_products id="' . esc_attr($id) . '"]';
                                        $used_posts = $this->find_posts_with_shortcode($id);
                                        $limit = isset($item['limit']) ? intval($item['limit']) : 8;
                                        $instock = !empty($item['instock']) ? 'فقط موجود' : 'همه کالاها';
                                    ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($item['title']); ?></strong></td>
                                        <td>
                                            <span class="capc-shortcode-box" onclick="navigator.clipboard.writeText('<?php echo $shortcode_str; ?>'); this.innerText='کپی شد ✓'; setTimeout(()=>this.innerText='<?php echo $shortcode_str; ?>', 1500);">
                                                <?php echo $shortcode_str; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div><?php echo count($item['cats']); ?> دسته‌بندی | <?php echo count($item['prods']); ?> محصول دستی</div>
                                            <small style="color:#64748b;">سقف نمایش: <strong><?php echo $limit; ?> عدد</strong> (<?php echo $instock; ?>)</small>
                                        </td>
                                        <td>
                                            <?php if (empty($used_posts)): ?>
                                                <span style="color:#94a3b8;">در هیچ نوشته‌ای استفاده نشده</span>
                                            <?php else: ?>
                                                <ul style="margin:0; padding-right:16px;">
                                                    <?php foreach ($used_posts as $p): ?>
                                                        <li style="margin-bottom:4px;">
                                                            <a href="<?php echo get_permalink($p->ID); ?>" target="_blank" style="font-weight:600; text-decoration:none;"><?php echo esc_html($p->post_title); ?></a>
                                                            <a href="<?php echo get_edit_post_link($p->ID); ?>" target="_blank" style="font-size:11px; color:#64748b;">[ویرایش]</a>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button type="button" class="button button-link-delete" onclick="deleteCarousel('<?php echo esc_attr($id); ?>')">حذف</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <script>
            let selectedCats = new Map();
            let selectedProds = new Map();

            function switchTab(tab, el) {
                document.querySelectorAll('.capc-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.capc-tab-content').forEach(c => c.style.display = 'none');
                el.classList.add('active');
                document.getElementById('tab-' + tab).style.display = 'block';
            }

            function renderSelectedTags(type) {
                const map = type === 'category' ? selectedCats : selectedProds;
                const tagBox = document.getElementById(type === 'category' ? 'tags_cats' : 'tags_prods');
                const countEl = document.getElementById(type === 'category' ? 'count_cats' : 'count_prods');

                countEl.textContent = map.size + ' انتخاب';
                if (map.size === 0) {
                    tagBox.style.display = 'none';
                    tagBox.innerHTML = '';
                    return;
                }

                tagBox.style.display = 'flex';
                let html = '';
                map.forEach((name, id) => {
                    html += `<div class="capc-tag">${name} <span onclick="removeTag('${type}', '${id}')">×</span></div>`;
                });
                tagBox.innerHTML = html;
            }

            function removeTag(type, id) {
                const map = type === 'category' ? selectedCats : selectedProds;
                map.delete(String(id));
                renderSelectedTags(type);
                const cb = document.querySelector(`input[data-type="${type}"][value="${id}"]`);
                if (cb) cb.checked = false;
            }

            function loadItems(type, search = '') {
                const container = document.getElementById(type === 'category' ? 'list_categories' : 'list_products');
                const data = new FormData();
                data.append('action', 'capc_search_items');
                data.append('type', type);
                data.append('search', search);
                data.append('nonce', '<?php echo wp_create_nonce("capc_nonce"); ?>');

                fetch(ajaxurl, { method: 'POST', body: data })
                .then(res => res.json())
                .then(res => {
                    if (res.success) {
                        let html = '';
                        const activeMap = type === 'category' ? selectedCats : selectedProds;
                        res.data.forEach(item => {
                            const checked = activeMap.has(String(item.id)) ? 'checked' : '';
                            const metaText = type === 'category' ? `${item.count} محصول` : `ID: ${item.id}`;
                            html += `
                            <label class="capc-check-row">
                                <span class="capc-check-row-right">
                                    <input type="checkbox" data-type="${type}" value="${item.id}" ${checked} onchange="toggleItem('${type}', '${item.id}', '${item.name.replace(/'/g, "\\'")}', this.checked)">
                                    <span>${item.name}</span>
                                </span>
                                <span class="capc-check-row-meta">${metaText}</span>
                            </label>`;
                        });
                        container.innerHTML = html || '<div style="padding:15px; text-align:center; color:#94a3b8;">موردی یافت نشد.</div>';
                    }
                });
            }

            function toggleItem(type, id, name, isChecked) {
                const map = type === 'category' ? selectedCats : selectedProds;
                if (isChecked) map.set(String(id), name);
                else map.delete(String(id));
                renderSelectedTags(type);
            }

            function saveCarousel() {
                const title = document.getElementById('capc_title').value.trim();
                const limit = parseInt(document.getElementById('capc_limit').value) || 8;
                const orderby = document.getElementById('capc_orderby').value;
                const instock = document.getElementById('capc_instock').checked ? 1 : 0;

                if (!title) { alert('لطفاً یک عنوان وارد کنید.'); return; }
                if (selectedCats.size === 0 && selectedProds.size === 0) { alert('حداقل یک محصول یا دسته‌بندی انتخاب کنید.'); return; }

                const data = new FormData();
                data.append('action', 'capc_save_carousel');
                data.append('title', title);
                data.append('limit', limit);
                data.append('orderby', orderby);
                data.append('instock', instock);
                data.append('cats', JSON.stringify(Array.from(selectedCats.keys())));
                data.append('prods', JSON.stringify(Array.from(selectedProds.keys())));
                data.append('nonce', '<?php echo wp_create_nonce("capc_nonce"); ?>');

                fetch(ajaxurl, { method: 'POST', body: data })
                .then(res => res.json())
                .then(res => {
                    if (res.success) {
                        document.getElementById('save_status').innerHTML = 'ذخیره شد! شورت‌کد: <code>' + res.data.shortcode + '</code>';
                        setTimeout(() => location.reload(), 1000);
                    }
                });
            }

            function deleteCarousel(id) {
                if (!confirm('آیا از حذف این کاروسل مطمئن هستید؟')) return;
                const data = new FormData();
                data.append('action', 'capc_delete_carousel');
                data.append('id', id);
                data.append('nonce', '<?php echo wp_create_nonce("capc_nonce"); ?>');
                fetch(ajaxurl, { method: 'POST', body: data }).then(() => location.reload());
            }

            function clearAllCaches() {
                const data = new FormData();
                data.append('action', 'capc_clear_cache');
                data.append('nonce', '<?php echo wp_create_nonce("capc_nonce"); ?>');
                fetch(ajaxurl, { method: 'POST', body: data }).then(() => alert('کش تمام کاروسل‌ها با موفقیت پاک شد.'));
            }

            document.addEventListener('DOMContentLoaded', () => {
                loadItems('category');
                loadItems('product');
            });
        </script>
        <?php
    }

    public function ajax_search_items() {
        check_ajax_referer('capc_nonce', 'nonce');
        $type = sanitize_text_field($_POST['type']);
        $search = sanitize_text_field($_POST['search']);
        $results = [];

        if ($type === 'category') {
            $terms = get_terms([
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'name__like' => $search,
                'number' => 60
            ]);
            foreach ($terms as $term) {
                $results[] = ['id' => $term->term_id, 'name' => $term->name, 'count' => $term->count];
            }
        } else {
            $query = new WP_Query([
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => 40,
                's' => $search
            ]);
            foreach ($query->posts as $post) {
                $results[] = ['id' => $post->ID, 'name' => $post->post_title];
            }
        }
        wp_send_json_success($results);
    }

    public function ajax_save_carousel() {
        check_ajax_referer('capc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();

        $carousels = get_option($this->option_name, []);
        $id = uniqid('c_');
        $limit = max(3, min(20, intval($_POST['limit'])));

        $carousels[$id] = [
            'title'   => sanitize_text_field($_POST['title']),
            'limit'   => $limit,
            'orderby' => sanitize_text_field($_POST['orderby']),
            'instock' => intval($_POST['instock']),
            'cats'    => array_map('intval', json_decode(stripslashes($_POST['cats']), true)),
            'prods'   => array_map('intval', json_decode(stripslashes($_POST['prods']), true)),
        ];
        update_option($this->option_name, $carousels);

        wp_send_json_success(['shortcode' => '[article_products id="' . $id . '"]']);
    }

    public function ajax_delete_carousel() {
        check_ajax_referer('capc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();

        $carousels = get_option($this->option_name, []);
        $id = sanitize_text_field($_POST['id']);
        unset($carousels[$id]);
        update_option($this->option_name, $carousels);
        delete_transient('capc_html_' . $id);
        wp_send_json_success();
    }

    public function ajax_clear_cache() {
        check_ajax_referer('capc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();
        $this->flush_all_carousel_caches();
        wp_send_json_success();
    }

    public function flush_all_carousel_caches() {
        $carousels = get_option($this->option_name, []);
        foreach (array_keys($carousels) as $id) {
            delete_transient('capc_html_' . $id);
        }
    }

    private function find_posts_with_shortcode($id) {
        global $wpdb;
        $like = '%' . $wpdb->esc_like('[article_products id="' . $id . '"') . '%';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s",
            $like
        ));
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(['id' => ''], $atts);
        $id = sanitize_text_field($atts['id']);
        $carousels = get_option($this->option_name, []);

        if (empty($id) || !isset($carousels[$id])) {
            return '';
        }

        $config = $carousels[$id];
        $orderby = $config['orderby'] ?? 'date';

        // استفاده از کش برای جلوگیری از اجرای کوئری سنگین در بازدیدهای مکرر (به جز حالت تصادفی)
        $cache_key = 'capc_html_' . $id;
        if ($orderby !== 'rand') {
            $cached_html = get_transient($cache_key);
            if ($cached_html !== false) {
                return $cached_html;
            }
        }

        $limit = isset($config['limit']) ? max(3, min(20, intval($config['limit']))) : 8;
        $instock = !empty($config['instock']);
        $product_ids = $config['prods'];

        // واکشی محدود و بهینه محصولات از دسته‌بندی‌های انتخاب‌شده
        if (!empty($config['cats'])) {
            $cat_args = [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => $limit,
                'fields'         => 'ids',
                'orderby'        => ($orderby === 'rand') ? 'rand' : 'date',
                'order'          => 'DESC',
                'tax_query'      => [[
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $config['cats']
                ]]
            ];

            if ($instock) {
                $cat_args['meta_query'] = [[
                    'key'   => '_stock_status',
                    'value' => 'instock'
                ]];
            }

            $cat_products = get_posts($cat_args);
            $product_ids = array_unique(array_merge($product_ids, $cat_products));
        }

        if (empty($product_ids)) {
            return '';
        }

        // اعمال دقیق سقف تعداد محصول (Limit) برای جلوگیری از سنگین شدن DOM صفحه
        $product_ids = array_slice($product_ids, 0, $limit);

        $query_args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'post__in'       => $product_ids,
            'posts_per_page' => $limit,
        ];

        if ($instock) {
            $query_args['meta_query'] = [[
                'key'   => '_stock_status',
                'value' => 'instock'
            ]];
        }

        switch ($orderby) {
            case 'popularity':
                $query_args['meta_key'] = 'total_sales';
                $query_args['orderby']  = 'meta_value_num';
                $query_args['order']    = 'DESC';
                break;
            case 'price':
                $query_args['meta_key'] = '_price';
                $query_args['orderby']  = 'meta_value_num';
                $query_args['order']    = 'ASC';
                break;
            case 'price-desc':
                $query_args['meta_key'] = '_price';
                $query_args['orderby']  = 'meta_value_num';
                $query_args['order']    = 'DESC';
                break;
            case 'rand':
                $query_args['orderby'] = 'rand';
                break;
            default:
                $query_args['orderby'] = 'post__in';
                break;
        }

        $loop = new WP_Query($query_args);
        if (!$loop->have_posts()) {
            return '';
        }

        $slider_id = 'capc-swiper-' . esc_attr($id) . '-' . wp_rand(100, 999);
        $enable_loop = ($loop->post_count > 3) ? 'true' : 'false';

        ob_start();
        ?>
        <div class="woocommerce capc-carousel-wrapper" id="wrap-<?php echo $slider_id; ?>">
            <div class="swiper <?php echo $slider_id; ?>" dir="rtl">
                <ul class="products swiper-wrapper">
                    <?php while ($loop->have_posts()) : $loop->the_post(); ?>
                        <div class="swiper-slide">
                            <?php wc_get_template_part('content', 'product'); ?>
                        </div>
                    <?php endwhile; ?>
                </ul>
            </div>

            <div class="capc-nav-btn capc-prev <?php echo $slider_id; ?>-prev" role="button" aria-label="قبلی">
                <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </div>
            <div class="capc-nav-btn capc-next <?php echo $slider_id; ?>-next" role="button" aria-label="بعدی">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"></polyline></svg>
            </div>
            <div class="capc-pagination <?php echo $slider_id; ?>-pagination"></div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof Swiper === 'undefined') return;

                const sliderEl = document.querySelector('.<?php echo $slider_id; ?>');
                const swiperInstance = new Swiper(sliderEl, {
                    slidesPerView: 1.35,
                    spaceBetween: 14,
                    speed: 900,
                    loop: <?php echo $enable_loop; ?>,
                    grabCursor: true,
                    watchOverflow: true,
                    autoplay: {
                        delay: 3200,
                        disableOnInteraction: false,
                        pauseOnMouseEnter: true
                    },
                    pagination: {
                        el: '.<?php echo $slider_id; ?>-pagination',
                        clickable: true
                    },
                    navigation: {
                        nextEl: '.<?php echo $slider_id; ?>-next',
                        prevEl: '.<?php echo $slider_id; ?>-prev'
                    },
                    breakpoints: {
                        640: { slidesPerView: 2, spaceBetween: 16 },
                        1024: { slidesPerView: 3, spaceBetween: 18 }
                    }
                });

                sliderEl.addEventListener('touchstart', function() {
                    if (swiperInstance.autoplay) swiperInstance.autoplay.stop();
                }, { passive: true });

                sliderEl.addEventListener('touchend', function() {
                    if (swiperInstance.autoplay) swiperInstance.autoplay.start();
                }, { passive: true });

                sliderEl.addEventListener('mousedown', function() {
                    if (swiperInstance.autoplay) swiperInstance.autoplay.stop();
                });

                document.addEventListener('mouseup', function() {
                    if (swiperInstance.autoplay && !sliderEl.matches(':hover')) {
                        swiperInstance.autoplay.start();
                    }
                });
            });
        </script>
        <?php
        wp_reset_postdata();
        $output = ob_get_clean();

        if ($orderby !== 'rand') {
            set_transient($cache_key, $output, 6 * HOUR_IN_SECONDS);
        }

        return $output;
    }
}

new Custom_Article_Product_Carousel();
