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
| `FTP_SERVER_DIR`  | Opcional. Padrão: `public_html/`. Use `./` se o FTP já abrir dentro de `public_html`. |

### Verificar se os arquivos chegaram no lugar certo

Cada deploy cria `https://upalorena.com.br/deploy-stamp.txt` com data, commit e id do run.

1. Rode o workflow (push, WordPress ou *Run workflow*).
2. Abra `/deploy-stamp.txt` no site (force refresh: Cmd+Shift+R).
3. Se o arquivo **não existir** ou a data for antiga, o FTP está gravando na pasta errada:
   - Crie o secret `FTP_SERVER_DIR` com valor `./` **ou** `public_html/` (o contrário do que estiver valendo).
4. No log do Action, busque linhas `uploading` — se só aparecer sucesso sem upload, apague no servidor o arquivo `.ftp-deploy-sync-state.json` (na pasta do site) e rode de novo.

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
