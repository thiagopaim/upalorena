<?php
/**
 * Plugin Name: UPA Lorena — Deploy automático
 * Description: Dispara o rebuild do site estático no GitHub Actions ao salvar conteúdo no WordPress.
 * Author: UPA Lorena
 *
 * Instalação:
 * 1. Copie este arquivo para wp-content/mu-plugins/upalorena-deploy.php
 *    (crie a pasta mu-plugins se não existir)
 * 2. Em wp-config.php, ANTES de "That's all, stop editing!", adicione:
 *
 *    define('UPALORENA_GH_TOKEN', 'github_pat_...');
 *    define('UPALORENA_GH_REPO', 'thiagopaim/upalorena');
 *
 * 3. O token precisa da permissão "Contents: Read" e "Metadata: Read"
 *    em repositórios públicos, ou scope "repo" em privados, além de
 *    permitir repository_dispatch (fine-grained: "Contents" read/write
 *    no repositório, ou classic token com scope "repo" / "public_repo").
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dispara repository_dispatch no GitHub (event_type: wordpress_update).
 */
function upalorena_trigger_deploy(string $reason = ''): void
{
    if (!defined('UPALORENA_GH_TOKEN') || UPALORENA_GH_TOKEN === '') {
        return;
    }

    $repo = defined('UPALORENA_GH_REPO') && UPALORENA_GH_REPO !== ''
        ? UPALORENA_GH_REPO
        : 'thiagopaim/upalorena';

    // Evita vários builds seguidos (ex.: salvar várias páginas em sequência)
    if (get_transient('upalorena_deploy_lock')) {
        return;
    }

    set_transient('upalorena_deploy_lock', 1, 2 * MINUTE_IN_SECONDS);

    $response = wp_remote_post(
        "https://api.github.com/repos/{$repo}/dispatches",
        [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . UPALORENA_GH_TOKEN,
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'UPA-Lorena-WordPress-Deploy',
            ],
            'body' => wp_json_encode([
                'event_type' => 'wordpress_update',
                'client_payload' => [
                    'reason' => $reason,
                    'site' => home_url(),
                ],
            ]),
        ]
    );

    if (is_wp_error($response)) {
        error_log('UPA Lorena deploy: ' . $response->get_error_message());
        delete_transient('upalorena_deploy_lock');
        return;
    }

    $code = (int) wp_remote_retrieve_response_code($response);

    // 204 = sucesso no repository_dispatch
    if ($code !== 204) {
        error_log(
            'UPA Lorena deploy: GitHub respondeu HTTP ' . $code . ' — ' .
            wp_remote_retrieve_body($response)
        );
        delete_transient('upalorena_deploy_lock');
    }
}

/**
 * Páginas e posts publicados/atualizados.
 */
add_action('save_post', function (int $post_id, WP_Post $post): void {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!in_array($post->post_status, ['publish', 'trash', 'private'], true)) {
        return;
    }

    if (!in_array($post->post_type, ['page', 'post'], true)) {
        return;
    }

    upalorena_trigger_deploy('save_post:' . $post->post_type . ':' . $post_id);
}, 20, 2);

/**
 * Menus (header do site).
 */
add_action('wp_update_nav_menu', function (): void {
    upalorena_trigger_deploy('nav_menu');
});

/**
 * Options pages do ACF (slider, dados globais, etc.).
 */
add_action('acf/save_post', function ($post_id): void {
    if ($post_id === 'options') {
        upalorena_trigger_deploy('acf_options');
        return;
    }

    if (is_string($post_id) && strpos($post_id, 'options') === 0) {
        upalorena_trigger_deploy('acf_options');
    }
}, 20);
