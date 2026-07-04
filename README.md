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
2. No `wp-config.php` do WordPress, **antes** de `That's all, stop editing!`:

```php
define('UPALORENA_GH_TOKEN', 'cole_o_token_aqui');
define('UPALORENA_GH_REPO', 'thiagopaim/upalorena');
```

### 3. Plugin no WordPress

Copie `wordpress/upalorena-deploy.php` para:

```
wp-content/mu-plugins/upalorena-deploy.php
```

Crie a pasta `mu-plugins` se ela não existir. Mu-plugins são carregados automaticamente.

### 4. Testar

- **Manual:** Actions → *Build and deploy* → *Run workflow*
- **Pelo WP:** publique ou atualize uma página e confira a aba Actions no GitHub

Há também um rebuild agendado a cada hora (UTC) como rede de segurança. Builds em sequência são limitados a um a cada 2 minutos no WordPress, e o workflow cancela execuções anteriores se um novo disparo chegar no meio do deploy.

A pasta `wp/` no servidor **não é apagada** pelo deploy FTP.
