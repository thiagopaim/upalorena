<?php
/**
 * Plugin Name: UPA Lorena Deploy
 * Description: Dispara o rebuild do site estatico no GitHub Actions ao salvar conteudo no WordPress.
 * Version: 1.1.0
 * Author: UPA Lorena
 * Text Domain: upalorena-deploy
 *
 * Instalacao (WordPress em /wp):
 * 1. Copie ESTA PASTA inteira para:
 *    wp/wp-content/plugins/upalorena-deploy/
 * 2. Em Plugins, ative "UPA Lorena Deploy"
 * 3. Em wp-config.php (o do /wp), antes de "That's all, stop editing!":
 *    define('UPALORENA_GH_TOKEN', 'github_pat_...');
 *    define('UPALORENA_GH_REPO', 'thiagopaim/upalorena');
 * 4. Abra no admin: menu "Deploy UPA"
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('UPALORENA_DEPLOY_OPTION')) {
    define('UPALORENA_DEPLOY_OPTION', 'upalorena_deploy_last');
}

if (!defined('UPALORENA_DEPLOY_LOCK')) {
    define('UPALORENA_DEPLOY_LOCK', 'upalorena_deploy_lock');
}

// Evita fatal se a versao antiga ainda estiver em mu-plugins.
if (function_exists('upalorena_trigger_deploy')) {
    return;
}

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

/**
 * @return array
 */
function upalorena_deploy_last()
{
    $last = get_option(UPALORENA_DEPLOY_OPTION, array());
    return is_array($last) ? $last : array();
}

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

/**
 * Dispara repository_dispatch no GitHub (event_type: wordpress_update).
 *
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
    $url = 'https://api.github.com/repos/' . $repo . '/dispatches';

    $response = wp_remote_post(
        $url,
        array(
            'timeout' => 20,
            'redirection' => 0,
            'headers' => array(
                'Authorization' => 'Bearer ' . $config['token'],
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'UPA-Lorena-WordPress-Deploy',
                'Content-Type' => 'application/json',
            ),
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
            'message' => 'Deploy disparado com sucesso no GitHub Actions.',
            'reason' => $reason,
            'code' => $code,
        );
        upalorena_deploy_store($result);
        return $result;
    }

    delete_transient(UPALORENA_DEPLOY_LOCK);

    $hint = '';
    if ($code === 401 || $code === 403) {
        $hint = ' Token invalido, expirado ou sem permissao (classic: public_repo/repo; fine-grained: Contents Read and write).';
    } elseif ($code === 404) {
        $hint = ' Repositorio nao encontrado ou token sem acesso a ' . $repo . '.';
    } elseif ($code === 422) {
        $hint = ' event_type invalido ou payload rejeitado.';
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

/**
 * Menu de nivel superior no admin (mais visivel que Ferramentas).
 */
add_action(
    'admin_menu',
    function () {
        add_menu_page(
            'Deploy UPA',
            'Deploy UPA',
            'manage_options',
            'upalorena-deploy',
            'upalorena_deploy_admin_page',
            'dashicons-update',
            80
        );
    }
);

add_action(
    'admin_notices',
    function () {
        if (!current_user_can('manage_options')) {
            return;
        }

        $config = upalorena_deploy_config();
        if ($config['configured']) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === 'toplevel_page_upalorena-deploy') {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo '<strong>UPA Lorena Deploy:</strong> defina <code>UPALORENA_GH_TOKEN</code> no <code>wp-config.php</code> ';
        echo 'ou abra <a href="' . esc_url(admin_url('admin.php?page=upalorena-deploy')) . '">Deploy UPA</a>.';
        echo '</p></div>';
    }
);

add_action(
    'admin_init',
    function () {
        if (!current_user_can('manage_options')) {
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

function upalorena_deploy_admin_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $config = upalorena_deploy_config();
    $last = upalorena_deploy_last();
    $lock = get_transient(UPALORENA_DEPLOY_LOCK);
    $plugin_file = plugin_basename(__FILE__);

    echo '<div class="wrap">';
    echo '<h1>Deploy do site (GitHub Actions)</h1>';

    if (isset($_GET['deployed'])) {
        if ($_GET['deployed'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Deploy disparado. Confira a aba Actions no GitHub em alguns segundos.</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Falha ao disparar o deploy. Veja o status abaixo.</p></div>';
        }
    }

    echo '<table class="widefat striped" style="max-width:720px;margin:1em 0;">';
    echo '<tbody>';
    echo '<tr><th style="width:220px">Plugin carregado</th><td><span style="color:green">Sim</span> — <code>' . esc_html($plugin_file) . '</code></td></tr>';
    echo '<tr><th>Caminho no servidor</th><td><code>' . esc_html(__FILE__) . '</code></td></tr>';
    echo '<tr><th>Token no wp-config.php</th><td>';
    if ($config['configured']) {
        echo '<span style="color:green">Configurado</span> (' . esc_html((string) strlen($config['token'])) . ' caracteres)';
    } else {
        echo '<span style="color:#b32d2e">Ausente</span> — adicione <code>define(\'UPALORENA_GH_TOKEN\', \'...\');</code> no <code>wp-config.php</code> do WordPress (pasta <code>/wp</code>).';
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
        echo '<tr><th style="width:220px">Quando</th><td>' . esc_html((string) (isset($last['time']) ? $last['time'] : '—')) . '</td></tr>';
        echo '<tr><th>Resultado</th><td style="color:' . ($ok ? 'green' : '#b32d2e') . '">' . ($ok ? 'Sucesso' : 'Falha') . '</td></tr>';
        echo '<tr><th>Motivo</th><td><code>' . esc_html((string) (isset($last['reason']) ? $last['reason'] : '—')) . '</code></td></tr>';
        if (isset($last['code'])) {
            echo '<tr><th>HTTP</th><td>' . esc_html((string) $last['code']) . '</td></tr>';
        }
        echo '<tr><th>Mensagem</th><td>' . esc_html((string) (isset($last['message']) ? $last['message'] : '—')) . '</td></tr>';
        echo '</tbody></table>';
    }

    echo '<form method="post" style="margin-top:1.5em">';
    wp_nonce_field('upalorena_deploy_now', 'upalorena_deploy_nonce');
    submit_button('Disparar deploy agora', 'primary', 'submit', false);
    echo '</form>';
    echo '</div>';
}
