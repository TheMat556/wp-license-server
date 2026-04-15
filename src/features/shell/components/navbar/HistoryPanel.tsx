interface HistoryEntry {
  label: string;
  url: string;
  visitedAt: string;
}

interface HistoryPanelProps {
  entries?: HistoryEntry[];
}

export function HistoryPanel({ entries = [] }: HistoryPanelProps) {
  return (
    <div className="history-panel" role="dialog" aria-label="Navigation history">
      <h3 className="history-panel__title">Recent pages</h3>
      {entries.length === 0 ? (
        <p className="history-panel__empty">No history yet.</p>
      ) : (
        <ul className="history-panel__list">
          {entries.map(entry => (
            <li key={entry.url} className="history-panel__item">
              <a href={entry.url} className="history-panel__link">
                {entry.label}
              </a>
              <time className="history-panel__time" dateTime={entry.visitedAt}>
                {new Date(entry.visitedAt).toLocaleTimeString()}
              </time>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
