<?php
/**
 * Plugin Name: UPA Lorena — Deploy automático
 * Description: Dispara o rebuild do site estático no GitHub Actions ao salvar conteúdo no WordPress.
 * Author: UPA Lorena
 *
 * Instalação:
 * 1. Copie este arquivo para wp-content/mu-plugins/upalorena-deploy.php
 *    (crie a pasta mu-plugins se não existir — o arquivo precisa ficar
 *    diretamente nessa pasta, não em um subdiretório)
 * 2. Em wp-config.php, ANTES de "That's all, stop editing!", adicione:
 *
 *    define('UPALORENA_GH_TOKEN', 'github_pat_...');
 *    define('UPALORENA_GH_REPO', 'thiagopaim/upalorena');
 *
 * 3. Token classic: scope "public_repo" (repo público) ou "repo" (privado).
 *    Token fine-grained: permissão Contents (Read and write) no repositório.
 *
 * 4. Em WP Admin → Ferramentas → Deploy do site, use "Disparar deploy agora"
 *    para testar e ver o status da última tentativa.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('UPALORENA_DEPLOY_OPTION', 'upalorena_deploy_last');
define('UPALORENA_DEPLOY_LOCK', 'upalorena_deploy_lock');

/**
 * @return array{ok:bool,configured:bool,repo:string}
 */
function upalorena_deploy_config(): array
{
    $token = defined('UPALORENA_GH_TOKEN') ? (string) UPALORENA_GH_TOKEN : '';
    $repo = defined('UPALORENA_GH_REPO') && UPALORENA_GH_REPO !== ''
        ? (string) UPALORENA_GH_REPO
        : 'thiagopaim/upalorena';

    return [
        'ok' => $token !== '',
        'configured' => $token !== '',
        'repo' => $repo,
        'token' => $token,
    ];
}

/**
 * @return array<string,mixed>
 */
function upalorena_deploy_last(): array
{
    $last = get_option(UPALORENA_DEPLOY_OPTION, []);
    return is_array($last) ? $last : [];
}

/**
 * @param array<string,mixed> $data
 */
function upalorena_deploy_store(array $data): void
{
    update_option(UPALORENA_DEPLOY_OPTION, array_merge([
        'time' => current_time('mysql'),
        'timestamp' => time(),
    ], $data), false);
}

/**
 * Dispara repository_dispatch no GitHub (event_type: wordpress_update).
 *
 * @param string $reason Motivo do disparo (para diagnóstico)
 * @param bool   $force  Ignora o lock de 2 minutos
 * @return array{success:bool,message:string,code?:int}
 */
function upalorena_trigger_deploy(string $reason = '', bool $force = false): array
{
    $config = upalorena_deploy_config();

    if (!$config['configured']) {
        $result = [
            'success' => false,
            'message' => 'UPALORENA_GH_TOKEN não está definido no wp-config.php.',
            'reason' => $reason,
        ];
        upalorena_deploy_store($result);
        return $result;
    }

    if (!$force && get_transient(UPALORENA_DEPLOY_LOCK)) {
        return [
            'success' => false,
            'message' => 'Ignorado: já houve um disparo nos últimos 2 minutos (lock ativo).',
            'reason' => $reason,
            'skipped' => true,
        ];
    }

    set_transient(UPALORENA_DEPLOY_LOCK, 1, 2 * MINUTE_IN_SECONDS);

    $repo = $config['repo'];
    $url = 'https://api.github.com/repos/' . $repo . '/dispatches';

    $response = wp_remote_post($url, [
        'timeout' => 20,
        'redirection' => 0,
        'headers' => [
            'Authorization' => 'Bearer ' . $config['token'],
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'UPA-Lorena-WordPress-Deploy',
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode([
            'event_type' => 'wordpress_update',
            'client_payload' => [
                'reason' => $reason,
                'site' => home_url(),
            ],
        ]),
    ]);

    if (is_wp_error($response)) {
        delete_transient(UPALORENA_DEPLOY_LOCK);
        $result = [
            'success' => false,
            'message' => 'Falha de rede ao chamar o GitHub: ' . $response->get_error_message(),
            'reason' => $reason,
        ];
        upalorena_deploy_store($result);
        error_log('UPA Lorena deploy: ' . $result['message']);
        return $result;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);

    // 204 = sucesso no repository_dispatch
    if ($code === 204) {
        $result = [
            'success' => true,
            'message' => 'Deploy disparado com sucesso no GitHub Actions.',
            'reason' => $reason,
            'code' => $code,
        ];
        upalorena_deploy_store($result);
        return $result;
    }

    delete_transient(UPALORENA_DEPLOY_LOCK);

    $hint = '';
    if ($code === 401 || $code === 403) {
        $hint = ' Token inválido, expirado ou sem permissão (classic: public_repo/repo; fine-grained: Contents Read and write).';
    } elseif ($code === 404) {
        $hint = ' Repositório não encontrado ou token sem acesso a ' . $repo . '.';
    } elseif ($code === 422) {
        $hint = ' event_type inválido ou payload rejeitado.';
    }

    $result = [
        'success' => false,
        'message' => 'GitHub respondeu HTTP ' . $code . '.' . $hint . ($body !== '' ? ' Resposta: ' . $body : ''),
        'reason' => $reason,
        'code' => $code,
    ];
    upalorena_deploy_store($result);
    error_log('UPA Lorena deploy: ' . $result['message']);
    return $result;
}

/**
 * Agenda um único disparo por request (save_post pode rodar várias vezes).
 */
function upalorena_schedule_deploy(string $reason): void
{
    static $scheduled = false;

    if ($scheduled) {
        return;
    }

    $scheduled = true;

    // Em alguns hosts o HTTP no meio do save trava o admin.
    add_action('shutdown', function () use ($reason): void {
        upalorena_trigger_deploy($reason);
    }, 20);
}

/**
 * Páginas e posts publicados/atualizados.
 */
add_action('save_post', function ($post_id, $post = null): void {
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

    if (!in_array($post->post_status, ['publish', 'trash', 'private'], true)) {
        return;
    }

    if (!in_array($post->post_type, ['page', 'post'], true)) {
        return;
    }

    upalorena_schedule_deploy('save_post:' . $post->post_type . ':' . $post_id);
}, 20, 2);

/**
 * Menus (header do site).
 */
add_action('wp_update_nav_menu', function (): void {
    upalorena_schedule_deploy('nav_menu');
});

/**
 * Options pages do ACF (slider, dados globais, etc.).
 */
add_action('acf/save_post', function ($post_id): void {
    $is_options = $post_id === 'options'
        || $post_id === 'option'
        || (is_string($post_id) && strpos($post_id, 'options') === 0);

    if (!$is_options) {
        return;
    }

    upalorena_schedule_deploy('acf_options');
}, 20);

/**
 * Página de diagnóstico em Ferramentas → Deploy do site.
 */
add_action('admin_menu', function (): void {
    add_management_page(
        'Deploy do site',
        'Deploy do site',
        'manage_options',
        'upalorena-deploy',
        'upalorena_deploy_admin_page'
    );
});

add_action('admin_notices', function (): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    $config = upalorena_deploy_config();
    if ($config['configured']) {
        return;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && $screen->id === 'tools_page_upalorena-deploy') {
        return;
    }

    echo '<div class="notice notice-warning"><p>';
    echo '<strong>UPA Lorena — Deploy:</strong> defina <code>UPALORENA_GH_TOKEN</code> no <code>wp-config.php</code> ';
    echo 'ou abra <a href="' . esc_url(admin_url('tools.php?page=upalorena-deploy')) . '">Ferramentas → Deploy do site</a>.';
    echo '</p></div>';
});

add_action('admin_init', function (): void {
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

    $redirect = add_query_arg(
        [
            'page' => 'upalorena-deploy',
            'deployed' => $result['success'] ? '1' : '0',
        ],
        admin_url('tools.php')
    );

    wp_safe_redirect($redirect);
    exit;
});

function upalorena_deploy_admin_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $config = upalorena_deploy_config();
    $last = upalorena_deploy_last();
    $lock = get_transient(UPALORENA_DEPLOY_LOCK);

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
    echo '<tr><th style="width:220px">Plugin carregado</th><td><span style="color:green">Sim</span> — este arquivo está ativo.</td></tr>';
    echo '<tr><th>Token no wp-config.php</th><td>';
    if ($config['configured']) {
        echo '<span style="color:green">Configurado</span> (' . esc_html(strlen($config['token'])) . ' caracteres)';
    } else {
        echo '<span style="color:#b32d2e">Ausente</span> — adicione <code>define(\'UPALORENA_GH_TOKEN\', \'...\');</code>';
    }
    echo '</td></tr>';
    echo '<tr><th>Repositório</th><td><code>' . esc_html($config['repo']) . '</code></td></tr>';
    echo '<tr><th>Lock (anti-spam)</th><td>' . ($lock ? 'Ativo (aguarde até 2 min ou use o botão abaixo, que ignora o lock)' : 'Inativo') . '</td></tr>';
    echo '</tbody></table>';

    echo '<h2>Última tentativa</h2>';
    if ($last === []) {
        echo '<p>Nenhuma tentativa registrada ainda. Use o botão abaixo ou salve uma página publicada.</p>';
    } else {
        $ok = !empty($last['success']);
        echo '<table class="widefat striped" style="max-width:720px;margin:1em 0;">';
        echo '<tbody>';
        echo '<tr><th style="width:220px">Quando</th><td>' . esc_html((string) ($last['time'] ?? '—')) . '</td></tr>';
        echo '<tr><th>Resultado</th><td style="color:' . ($ok ? 'green' : '#b32d2e') . '">' . ($ok ? 'Sucesso' : 'Falha') . '</td></tr>';
        echo '<tr><th>Motivo</th><td><code>' . esc_html((string) ($last['reason'] ?? '—')) . '</code></td></tr>';
        if (isset($last['code'])) {
            echo '<tr><th>HTTP</th><td>' . esc_html((string) $last['code']) . '</td></tr>';
        }
        echo '<tr><th>Mensagem</th><td>' . esc_html((string) ($last['message'] ?? '—')) . '</td></tr>';
        echo '</tbody></table>';
    }

    echo '<form method="post" style="margin-top:1.5em">';
    wp_nonce_field('upalorena_deploy_now', 'upalorena_deploy_nonce');
    submit_button('Disparar deploy agora', 'primary', 'submit', false);
    echo '</form>';

    echo '<p style="margin-top:2em;color:#646970;max-width:720px">';
    echo 'Se o botão funcionar mas salvar página não, o problema está nos hooks. ';
    echo 'Se o botão falhar, a mensagem acima indica token, permissão ou bloqueio de saída para <code>api.github.com</code> na HostGator.';
    echo '</p>';
    echo '</div>';
}
