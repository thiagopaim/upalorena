<?php
/**
 * Plugin Name: UPA Lorena Deploy
 * Description: Dispara o rebuild do site estatico no GitHub Actions ao salvar conteudo no WordPress.
 * Version: 1.2.2
 * Author: UPA Lorena
 * Text Domain: upalorena-deploy
 *
 * Instalacao (WordPress em /wp):
 * 1. Apague QUALQUER upalorena-deploy.php em wp/wp-content/mu-plugins/
 * 2. Copie ESTA PASTA para: wp/wp-content/plugins/upalorena-deploy/
 * 3. Ative o plugin em Plugins
 * 4. No wp-config.php de /wp:
 *    define('UPALORENA_GH_TOKEN', 'github_pat_...');
 *    define('UPALORENA_GH_REPO', 'thiagopaim/upalorena');
 * 5. Acesse: /wp/wp-admin/admin.php?page=upalorena-deploy
 *    ou o link "Abrir Deploy UPA" na lista de Plugins
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('UPALORENA_DEPLOY_LOADED')) {
    return;
}

define('UPALORENA_DEPLOY_LOADED', true);
define('UPALORENA_DEPLOY_OPTION', 'upalorena_deploy_last');
define('UPALORENA_DEPLOY_LOCK', 'upalorena_deploy_lock');
define('UPALORENA_DEPLOY_FILE', __FILE__);

if (!function_exists('upalorena_deploy_config')) {
    /**
     * @return array
     */
    function upalorena_deploy_config()
    {
        $token = defined('UPALORENA_GH_TOKEN') ? (string) UPALORENA_GH_TOKEN : '';
        $repo = (defined('UPALORENA_GH_REPO') && UPALORENA_GH_REPO !== '')
            ? (string) UPALORENA_GH_REPO
            : 'thiagopaim/upalorena';

        return array(
            'configured' => $token !== '',
            'repo' => $repo,
            'token' => $token,
        );
    }
}

if (!function_exists('upalorena_deploy_last')) {
    /**
     * @return array
     */
    function upalorena_deploy_last()
    {
        $last = get_option(UPALORENA_DEPLOY_OPTION, array());
        return is_array($last) ? $last : array();
    }
}

if (!function_exists('upalorena_deploy_store')) {
    /**
     * @param array $data
     */
    function upalorena_deploy_store($data)
    {
        update_option(
            UPALORENA_DEPLOY_OPTION,
            array_merge(
                array(
                    'time' => current_time('mysql'),
                    'timestamp' => time(),
                ),
                $data
            ),
            false
        );
    }
}

if (!function_exists('upalorena_trigger_deploy')) {
    /**
     * @param string $reason
     * @param bool   $force
     * @return array
     */
    function upalorena_trigger_deploy($reason = '', $force = false)
    {
        $config = upalorena_deploy_config();

        if (!$config['configured']) {
            $result = array(
                'success' => false,
                'message' => 'UPALORENA_GH_TOKEN nao esta definido no wp-config.php.',
                'reason' => $reason,
            );
            upalorena_deploy_store($result);
            return $result;
        }

        if (!$force && get_transient(UPALORENA_DEPLOY_LOCK)) {
            return array(
                'success' => false,
                'message' => 'Ignorado: ja houve um disparo nos ultimos 2 minutos (lock ativo).',
                'reason' => $reason,
                'skipped' => true,
            );
        }

        set_transient(UPALORENA_DEPLOY_LOCK, 1, 2 * MINUTE_IN_SECONDS);

        $repo = $config['repo'];
        $headers = array(
            'Authorization' => 'Bearer ' . $config['token'],
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'UPA-Lorena-WordPress-Deploy',
            'Content-Type' => 'application/json',
        );

        // 1) workflow_dispatch (mais confiavel para Actions)
        $workflow_url = 'https://api.github.com/repos/' . $repo . '/actions/workflows/deploy.yml/dispatches';
        $response = wp_remote_post(
            $workflow_url,
            array(
                'timeout' => 20,
                'redirection' => 0,
                'headers' => $headers,
                'body' => wp_json_encode(
                    array(
                        'ref' => 'main',
                    )
                ),
            )
        );

        // 2) Fallback: repository_dispatch
        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) !== 204) {
            $response = wp_remote_post(
                'https://api.github.com/repos/' . $repo . '/dispatches',
                array(
                    'timeout' => 20,
                    'redirection' => 0,
                    'headers' => $headers,
                    'body' => wp_json_encode(
                        array(
                            'event_type' => 'wordpress_update',
                            'client_payload' => array(
                                'reason' => $reason,
                                'site' => home_url(),
                            ),
                        )
                    ),
                )
            );
        }

        if (is_wp_error($response)) {
            delete_transient(UPALORENA_DEPLOY_LOCK);
            $result = array(
                'success' => false,
                'message' => 'Falha de rede ao chamar o GitHub: ' . $response->get_error_message(),
                'reason' => $reason,
            );
            upalorena_deploy_store($result);
            return $result;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code === 204) {
            $result = array(
                'success' => true,
                'message' => 'Disparo aceito pelo GitHub (HTTP 204). Isso nao confirma upload dos arquivos — confira o workflow em Actions e /deploy-stamp.txt no site.',
                'reason' => $reason,
                'code' => $code,
            );
            upalorena_deploy_store($result);
            return $result;
        }

        delete_transient(UPALORENA_DEPLOY_LOCK);

        $hint = '';
        if ($code === 401 || $code === 403) {
            $hint = ' Crie um token CLASSIC (nao fine-grained) em https://github.com/settings/tokens'
                . ' com a permissao "repo" marcada, e cole no wp-config.php como UPALORENA_GH_TOKEN.'
                . ' Esse token NAO vai nos Secrets do Actions (FTP_*); fica so no WordPress.';
        } elseif ($code === 404) {
            $hint = ' Repositorio nao encontrado, workflow deploy.yml ausente na branch main, ou token sem acesso a ' . $repo . '.';
        } elseif ($code === 422) {
            $hint = ' Payload rejeitado (confira se a branch main existe).';
        }

        $result = array(
            'success' => false,
            'message' => 'GitHub respondeu HTTP ' . $code . '.' . $hint . ($body !== '' ? ' Resposta: ' . $body : ''),
            'reason' => $reason,
            'code' => $code,
        );
        upalorena_deploy_store($result);
        return $result;
    }
}

if (!function_exists('upalorena_schedule_deploy')) {
    /**
     * @param string $reason
     */
    function upalorena_schedule_deploy($reason)
    {
        static $scheduled = false;

        if ($scheduled) {
            return;
        }

        $scheduled = true;

        add_action(
            'shutdown',
            function () use ($reason) {
                upalorena_trigger_deploy($reason);
            },
            20
        );
    }
}

if (!function_exists('upalorena_deploy_admin_page')) {
    function upalorena_deploy_admin_page()
    {
        if (!current_user_can('read')) {
            wp_die('Sem permissao.');
        }

        $config = upalorena_deploy_config();
        $last = upalorena_deploy_last();
        $lock = get_transient(UPALORENA_DEPLOY_LOCK);

        echo '<div class="wrap">';
        echo '<h1>Deploy do site (GitHub Actions)</h1>';

        if (isset($_GET['deployed'])) {
            if ($_GET['deployed'] === '1') {
                echo '<div class="notice notice-success is-dismissible"><p><strong>Disparo aceito.</strong> O GitHub vai buildar e enviar os arquivos por FTP. Isso leva 1–3 minutos — acompanhe em Actions e valide <code>/deploy-stamp.txt</code> (e as datas dos HTML no File Manager).</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Falha ao disparar o deploy. Veja o status abaixo.</p></div>';
            }
        }

        echo '<table class="widefat striped" style="max-width:720px;margin:1em 0;">';
        echo '<tbody>';
        echo '<tr><th style="width:220px">Plugin carregado</th><td><span style="color:green">Sim</span></td></tr>';
        echo '<tr><th>Arquivo</th><td><code>' . esc_html(UPALORENA_DEPLOY_FILE) . '</code></td></tr>';
        echo '<tr><th>Token no wp-config.php</th><td>';
        if ($config['configured']) {
            echo '<span style="color:green">Configurado</span> (' . esc_html((string) strlen($config['token'])) . ' caracteres)';
        } else {
            echo '<span style="color:#b32d2e">Ausente</span> — adicione <code>define(\'UPALORENA_GH_TOKEN\', \'...\');</code> no <code>wp-config.php</code> da pasta <code>/wp</code>.';
        }
        echo '</td></tr>';
        echo '<tr><th>Repositorio</th><td><code>' . esc_html($config['repo']) . '</code></td></tr>';
        echo '<tr><th>Lock (anti-spam)</th><td>' . ($lock ? 'Ativo (o botao abaixo ignora o lock)' : 'Inativo') . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2>Ultima tentativa</h2>';
        if ($last === array()) {
            echo '<p>Nenhuma tentativa registrada ainda. Use o botao abaixo ou salve uma pagina publicada.</p>';
        } else {
            $ok = !empty($last['success']);
            echo '<table class="widefat striped" style="max-width:720px;margin:1em 0;">';
            echo '<tbody>';
            echo '<tr><th style="width:220px">Quando</th><td>' . esc_html((string) (isset($last['time']) ? $last['time'] : '-')) . '</td></tr>';
            echo '<tr><th>Resultado do disparo</th><td style="color:' . ($ok ? 'green' : '#b32d2e') . '">' . ($ok ? 'Aceito pelo GitHub' : 'Falha') . '</td></tr>';
            echo '<tr><th>Motivo</th><td><code>' . esc_html((string) (isset($last['reason']) ? $last['reason'] : '-')) . '</code></td></tr>';
            if (isset($last['code'])) {
                echo '<tr><th>HTTP</th><td>' . esc_html((string) $last['code']) . '</td></tr>';
            }
            echo '<tr><th>Mensagem</th><td>' . esc_html((string) (isset($last['message']) ? $last['message'] : '-')) . '</td></tr>';
            echo '</tbody></table>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=upalorena-deploy')) . '" style="margin-top:1.5em">';
        wp_nonce_field('upalorena_deploy_now', 'upalorena_deploy_nonce');
        submit_button('Disparar deploy agora', 'primary', 'submit', false);
        echo '</form>';
        echo '</div>';
    }
}

if (!function_exists('upalorena_deploy_register_menu')) {
    function upalorena_deploy_register_menu()
    {
        add_menu_page(
            'Deploy UPA',
            'Deploy UPA',
            'read',
            'upalorena-deploy',
            'upalorena_deploy_admin_page',
            'dashicons-update',
            3
        );

        add_submenu_page(
            'tools.php',
            'Deploy UPA',
            'Deploy UPA',
            'read',
            'upalorena-deploy',
            'upalorena_deploy_admin_page'
        );
    }
}

add_action('admin_menu', 'upalorena_deploy_register_menu', 9);

add_action(
    'save_post',
    function ($post_id, $post = null) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!($post instanceof WP_Post)) {
            $post = get_post($post_id);
        }

        if (!($post instanceof WP_Post)) {
            return;
        }

        if (!in_array($post->post_status, array('publish', 'trash', 'private'), true)) {
            return;
        }

        if (!in_array($post->post_type, array('page', 'post'), true)) {
            return;
        }

        upalorena_schedule_deploy('save_post:' . $post->post_type . ':' . $post_id);
    },
    20,
    2
);

add_action(
    'wp_update_nav_menu',
    function () {
        upalorena_schedule_deploy('nav_menu');
    }
);

add_action(
    'acf/save_post',
    function ($post_id) {
        $is_options = $post_id === 'options'
            || $post_id === 'option'
            || (is_string($post_id) && strpos($post_id, 'options') === 0);

        if (!$is_options) {
            return;
        }

        upalorena_schedule_deploy('acf_options');
    },
    20
);

add_filter(
    'plugin_action_links_' . plugin_basename(UPALORENA_DEPLOY_FILE),
    function ($links) {
        $url = admin_url('admin.php?page=upalorena-deploy');
        array_unshift(
            $links,
            '<a href="' . esc_url($url) . '"><strong>Abrir Deploy UPA</strong></a>'
        );
        return $links;
    }
);

add_action(
    'admin_notices',
    function () {
        if (!current_user_can('read')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $on_plugin_page = $screen && $screen->id === 'plugins';

        if ($on_plugin_page) {
            echo '<div class="notice notice-success"><p>';
            echo '<strong>UPA Lorena Deploy esta ativo.</strong> ';
            echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=upalorena-deploy')) . '">Abrir painel de deploy</a> ';
            echo '<code>' . esc_html(UPALORENA_DEPLOY_FILE) . '</code>';
            echo '</p></div>';
        }
    }
);

add_action(
    'admin_init',
    function () {
        if (!current_user_can('read')) {
            return;
        }

        if (
            !isset($_POST['upalorena_deploy_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['upalorena_deploy_nonce'])), 'upalorena_deploy_now')
        ) {
            return;
        }

        $result = upalorena_trigger_deploy('manual_admin', true);

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'upalorena-deploy',
                    'deployed' => $result['success'] ? '1' : '0',
                ),
                admin_url('admin.php')
            )
        );
        exit;
    }
);
