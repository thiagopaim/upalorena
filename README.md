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

Crie a pasta `mu-plugins` se ela não existir. O arquivo precisa ficar **diretamente** nessa pasta (não em um subdiretório). Mu-plugins são carregados automaticamente — não aparece em “Plugins”.

### 4. Testar e diagnosticar

No WordPress: **Ferramentas → Deploy do site**.

1. Confirme que o plugin está carregado e o token configurado.
2. Clique em **Disparar deploy agora**.
3. A página mostra o resultado (sucesso, HTTP 401/404, bloqueio de rede, etc.).
4. Se o botão funcionar, salvar uma página publicada também deve disparar o deploy.

No GitHub: Actions → *Build and deploy*.

Há também um rebuild agendado a cada hora (UTC) como rede de segurança. Builds em sequência são limitados a um a cada 2 minutos no WordPress, e o workflow cancela execuções anteriores se um novo disparo chegar no meio do deploy.

A pasta `wp/` no servidor **não é apagada** pelo deploy FTP.

### Problemas comuns

| Sintoma | Causa provável |
| :------ | :------------- |
| Página “Deploy do site” não existe | Arquivo não está em `wp-content/mu-plugins/upalorena-deploy.php` |
| Token ausente | Falta `UPALORENA_GH_TOKEN` no `wp-config.php` |
| HTTP 401/403 | Token inválido ou sem permissão `public_repo` / Contents write |
| HTTP 404 | Repo errado em `UPALORENA_GH_REPO` ou token sem acesso |
| Falha de rede | HostGator bloqueando saída para `api.github.com` |
