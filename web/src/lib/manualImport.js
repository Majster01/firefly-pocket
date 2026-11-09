const plural = (count, word) => `${count} ${count === 1 ? word : `${word}s`}`

// The suggester runs after a failed import too: say what it made, besides the failure
export function manualDetail(manual) {
  if (manual.status !== 'done' && manual.status !== 'failed') return ''
  if (manual.suggest_error) return ` · suggester failed: ${manual.suggest_error}`
  const suggestions = plural(manual.suggestions?.written ?? 0, 'suggestion')
  if (manual.status === 'failed') return manual.suggestions?.written ? ` · ${suggestions}` : ''
  return ` · ${manual.summary?.messages ?? 0} new · ${suggestions}`
}
