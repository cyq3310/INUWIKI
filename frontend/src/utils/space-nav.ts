import type { ArticleItem } from '../types'

export interface ArticlePage {
  list: ArticleItem[]
  total: number
}

/**
 * 分页拉全某空间的文章，供侧栏目录树使用。
 * 后端门户单页最多 50、后台最多 100，调用方传对应 size；maxPages 是防止异常 total 导致死循环的保险。
 */
export async function fetchAllSpaceArticles(
  fetchPage: (page: number, size: number) => Promise<ArticlePage>,
  size = 50,
  maxPages = 20,
): Promise<ArticleItem[]> {
  const out: ArticleItem[] = []
  for (let page = 1; page <= maxPages; page += 1) {
    const res = await fetchPage(page, size)
    const list = res.list ?? []
    out.push(...list)
    if (list.length < size || out.length >= (res.total || 0)) break
  }
  return out
}
