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

| Secret            | Valor                                              |
| :---------------- | :------------------------------------------------- |
| `FTP_SERVER`      | Host FTP da HostGator (ex.: `ftp.upalorena.com.br`) |
| `FTP_USERNAME`    | Usuário FTP                                        |
| `FTP_PASSWORD`    | Senha FTP                                          |
| `FTP_SERVER_DIR`  | Opcional. Padrão: `website/`. Se o FTP já abrir em `public_html`, use `website/`; se abrir na home, use `public_html/website/`. |

### Mascarar `/website/` na raiz (`.htaccess`)

O Astro é publicado em `public_html/website/`, mas as URLs públicas ficam na raiz (`/`), sem mostrar `/website/`. O WordPress em `/wp/` não é afetado.

1. Envie **uma vez** (FTP/cPanel) o arquivo `hosting/public_html.htaccess` para:
   ```
   public_html/.htaccess
   ```
2. Se existir `public_html/index.html` antigo na raiz, **remova ou renomeie** — senão a home pode continuar servindo o arquivo velho.
3. O deploy automático **não** sobrescreve esse `.htaccess` (ele fica fora de `website/`).

### Verificar se os arquivos chegaram no lugar certo

Cada deploy cria `deploy-stamp.txt` dentro de `website/`. Com o `.htaccess`, a URL pública é:

`https://upalorena.com.br/deploy-stamp.txt`

1. Rode o workflow (push, WordPress ou *Run workflow*).
2. Abra `/deploy-stamp.txt` (force refresh: Cmd+Shift+R).
3. Se a data for antiga ou der 404, confira `FTP_SERVER_DIR` e se o `.htaccess` está na raiz do `public_html`.
4. No log do Action, busque linhas `uploading` nos HTML (não só em `deploy-stamp.txt`). O workflow apaga o state do FTP antes de cada deploy para forçar reenvio completo.

**Importante:** o painel “Deploy UPA” no WordPress só confirma que o GitHub *aceitou* o disparo (HTTP 204). O upload real é o job em Actions; se só `deploy-stamp.txt` mudar de data e os `.html` não, o build repetiu conteúdo antigo (cache da API) ou o state do FTP pulou os arquivos.

### 2. Token do GitHub para o WordPress

O token fica **só no WordPress** (`wp-config.php`). Não é um secret do Actions — os secrets `FTP_*` servem apenas para o upload.

Use um token **Classic** (fine-grained costuma retornar HTTP 403 neste endpoint):

1. Abra [Tokens (classic)](https://github.com/settings/tokens)
2. **Generate new token (classic)**
3. Note: `upa-lorena-deploy`
4. Expiration: o que preferir (90 days / No expiration)
5. Marque o scope **`repo`** (o grupo inteiro)
6. Generate token e copie (`ghp_...`)

No `wp-config.php` **do WordPress em `/wp`**, antes de `That's all, stop editing!`:

```php
define('UPALORENA_GH_TOKEN', 'ghp_cole_o_token_aqui');
define('UPALORENA_GH_REPO', 'thiagopaim/upalorena');
```

Se o token antigo era fine-grained, apague-o e use o classic acima.

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
