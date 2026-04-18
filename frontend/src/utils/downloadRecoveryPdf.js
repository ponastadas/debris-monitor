import { jsPDF } from 'jspdf';

/**
 * Generate and immediately download a PDF containing MFA recovery codes.
 * All generation is client-side — codes never leave the browser.
 *
 * @param {string[]} codes  Array of plain-text recovery codes (XXXXX-XXXXX format).
 */
export function downloadRecoveryCodesPdf(codes) {
  const doc  = new jsPDF({ unit: 'mm', format: 'a4' });
  const date = new Date().toISOString().slice(0, 10);

  // ── Header ────────────────────────────────────────────────────────────────
  doc.setFont('courier', 'bold');
  doc.setFontSize(15);
  doc.setTextColor(0, 150, 160);
  doc.text('DEBRIS MONITOR', 20, 26);

  doc.setFontSize(12);
  doc.setTextColor(30, 30, 30);
  doc.text('MFA Recovery Codes', 20, 35);

  doc.setFont('courier', 'normal');
  doc.setFontSize(9);
  doc.setTextColor(120, 120, 120);
  doc.text(`Generated: ${date}`, 20, 42);

  doc.setDrawColor(200, 200, 200);
  doc.setLineWidth(0.3);
  doc.line(20, 47, 190, 47);

  // ── Codes ─────────────────────────────────────────────────────────────────
  doc.setFont('courier', 'bold');
  doc.setFontSize(13);
  doc.setTextColor(20, 20, 20);

  codes.forEach((code, i) => {
    const col = i % 2;
    const row = Math.floor(i / 2);
    doc.text(code, col === 0 ? 20 : 110, 62 + row * 13);
  });

  // ── Warnings ──────────────────────────────────────────────────────────────
  const warningY = 62 + Math.ceil(codes.length / 2) * 13 + 10;

  doc.setFont('courier', 'normal');
  doc.setFontSize(9);
  doc.setTextColor(140, 100, 0);
  doc.text('! Each code can be used only once.', 20, warningY);
  doc.text('! Store this document securely and do not share these codes with anyone.', 20, warningY + 7);

  doc.setTextColor(160, 160, 160);
  doc.setFontSize(8);
  doc.text(
    'These codes are not stored in plaintext by Debris Monitor.',
    20,
    warningY + 18,
  );

  doc.save(`debris-monitor-recovery-codes-${date}.pdf`);
}
