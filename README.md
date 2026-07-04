# UPA Lorena

Site estático em [Astro](https://astro.build), com conteúdo vindo da API REST do WordPress (`/wp`).

## Comandos

| Comando           | Ação                                      |
| :---------------- | :---------------------------------------- |
| `npm install`     | Instala dependências                      |
| `npm run dev`     | Servidor local de desenvolvimento         |
| `npm run build`   | Gera o site em `./dist/`                  |
| `npm run preview` | Pré-visualiza o build localmente          |

## Deploy automático (HostGator + GitHub Actions)

O servidor da HostGator é só PHP, então o build roda no GitHub Actions e o `dist/` é enviado por FTP. O WordPress dispara um rebuild ao salvar páginas, menus ou options do ACF.

### 1. Secrets no GitHub

No repositório: **Settings → Secrets and variables → Actions**, crie:

| Secret          | Valor                                              |
| :-------------- | :------------------------------------------------- |
| `FTP_SERVER`    | Host FTP da HostGator (ex.: `ftp.upalorena.com.br`) |
| `FTP_USERNAME`  | Usuário FTP                                        |
| `FTP_PASSWORD`  | Senha FTP                                          |

Se o FTP abrir na home da conta (e não em `public_html`), edite `server-dir` em `.github/workflows/deploy.yml` para `public_html/`.

### 2. Token do GitHub para o WordPress

1. Crie um [Personal Access Token](https://github.com/settings/tokens) (classic com `public_repo`, ou fine-grained com permissão de **Contents** no repositório `thiagopaim/upalorena`).
2. No `wp-config.php` **do WordPress em `/wp`**, antes de `That's all, stop editing!`:

```php
define('UPALORENA_GH_TOKEN', 'cole_o_token_aqui');
define('UPALORENA_GH_REPO', 'thiagopaim/upalorena');
```

### 3. Plugin no WordPress

O WordPress deste projeto fica em `/wp`, então o caminho no servidor é:

```
wp/wp-content/plugins/upalorena-deploy/upalorena-deploy.php
```

**Não** use `public_html/wp-content/...` (sem o `/wp`) — o WordPress não carrega daí.

Passos:

1. Apague qualquer `upalorena-deploy.php` antigo em `mu-plugins` (mu-plugin **não** aparece em Plugins e é fácil colocar no lugar errado).
2. Envie a pasta `wordpress/upalorena-deploy/` do repositório para:
   ```
   wp/wp-content/plugins/upalorena-deploy/
   ```
3. No admin (`/wp/wp-admin`): **Plugins → Plugins instalados**.
4. Ative **UPA Lorena Deploy**.
5. Deve aparecer o menu lateral **Deploy UPA**.

### 4. Testar

1. Abra **Deploy UPA** no menu lateral.
2. Confira se o token está configurado.
3. Clique em **Disparar deploy agora**.
4. Veja a aba Actions no GitHub.

Há também um rebuild agendado a cada hora (UTC) como rede de segurança.

A pasta `wp/` no servidor **não é apagada** pelo deploy FTP.

### Problemas comuns

| Sintoma | Causa provável |
| :------ | :------------- |
| Plugin não aparece em Plugins | Pasta no caminho errado (falta o `/wp/`) |
| Plugin aparece mas está inativo | Ativar em Plugins |
| Token ausente | Falta `UPALORENA_GH_TOKEN` no `wp-config.php` de `/wp` |
| HTTP 401/403 | Token inválido ou sem `public_repo` / Contents write |
| HTTP 404 | Repo errado em `UPALORENA_GH_REPO` ou token sem acesso |
| Falha de rede | HostGator bloqueando saída para `api.github.com` |
