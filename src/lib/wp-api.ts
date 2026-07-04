const WP_API_BASE = "https://upalorena.com.br/wp/wp-json";

/**
 * Fetch na API do WordPress sem cache (CDN/LiteSpeed/proxy).
 * Sem isso o build no Actions pode reutilizar HTML antigo e o FTP
 * só envia deploy-stamp.txt (hash dos .html não muda).
 */
export async function fetchWp(path: string): Promise<Response> {
  const url = new URL(
    path.startsWith("http")
      ? path
      : `${WP_API_BASE}${path.startsWith("/") ? path : `/${path}`}`,
  );

  const buildId =
    process.env.GITHUB_RUN_ID ||
    process.env.BUILD_ID ||
    String(Date.now());

  url.searchParams.set("_build", buildId);

  return fetch(url.toString(), {
    headers: {
      "Cache-Control": "no-cache, no-store, must-revalidate",
      Pragma: "no-cache",
    },
    cache: "no-store",
  });
}
