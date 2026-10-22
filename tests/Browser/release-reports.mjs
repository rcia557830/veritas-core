import { runJournal as ledger } from './general-ledger.mjs';
import { runJournal as reports } from './financial-reports.mjs';

export async function runJournal(helpers) {
  await ledger(helpers);
  await reports(helpers);
}
