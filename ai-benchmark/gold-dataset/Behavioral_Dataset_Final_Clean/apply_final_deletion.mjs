/**
 * FINAL deletion-only cleanup for Parts 02–50.
 * Does not rewrite records. Does not modify Behavioral_Dataset_Cleaned or Part 01.
 */
import fs from "fs";
import path from "path";
import crypto from "crypto";
import { fileURLToPath } from "url";
import AdmZip from "adm-zip";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, "..");
const CLEAN = path.join(ROOT, "Behavioral_Dataset_Cleaned", "behavioral_dataset.jsonl");
const BENCH = path.join(ROOT, "Behavioral_Dataset_Benchmark");
const P01 = path.join(ROOT, "Part_01_Cleaned", "Part_01_behavioral_dataset_clean.jsonl");
const OUT = __dirname;

const PARTS_02_50 = [
  ["الملف_01/Part_02", 501],
  ["الملف_01/Part_03", 501],
  ["الملف_01/Part_04", 501],
  ["الملف_01/Part_05", 501],
  ["الملف_02/Part_01", 472],
  ["الملف_02/Part_02", 449],
  ["الملف_02/Part_03", 426],
  ["الملف_02/Part_04", 473],
  ["الملف_02/Part_05", 443],
  ["الملف_03/Part_01", 423],
  ["الملف_03/Part_02", 437],
  ["الملف_03/Part_03", 453],
  ["الملف_03/Part_04", 429],
  ["الملف_03/Part_05", 402],
  ["الملف_04/Part_01", 446],
  ["الملف_04/Part_02", 448],
  ["الملف_04/Part_03", 439],
  ["الملف_04/Part_04", 437],
  ["الملف_04/Part_05", 453],
  ["الملف_05/Part_01", 454],
  ["الملف_05/Part_02", 451],
  ["الملف_05/Part_03", 451],
  ["الملف_05/Part_04", 448],
  ["الملف_05/Part_05", 446],
  ["الملف_06/Part_01", 439],
  ["الملف_06/Part_02", 432],
  ["الملف_06/Part_03", 437],
  ["الملف_06/Part_04", 440],
  ["الملف_06/Part_05", 432],
  ["الملف_07/Part_01", 470],
  ["الملف_07/Part_02", 435],
  ["الملف_07/Part_03", 440],
  ["الملف_07/Part_04", 442],
  ["الملف_07/Part_05", 440],
  ["الملف_08/Part_01", 502],
  ["الملف_08/Part_02", 502],
  ["الملف_08/Part_03", 502],
  ["الملف_08/Part_04", 502],
  ["الملف_08/Part_05", 502],
  ["الملف_09/Part_01", 502],
  ["الملف_09/Part_02", 502],
  ["الملف_09/Part_03", 502],
  ["الملف_09/Part_04", 502],
  ["الملف_09/Part_05", 501],
  ["الملف_10/Part_01", 502],
  ["الملف_10/Part_02", 502],
  ["الملف_10/Part_03", 502],
  ["الملف_10/Part_04", 502],
  ["الملف_10/Part_05", 501],
];

/**
 * Core P1 records whose answers enact the wrong behavior (cannot keep without rewrite).
 * Borderline P1 (clarification-only, valid answer despite label) are NOT listed.
 */
const P1_SEMANTIC_DELETE = [
  {
    key: "الملف_02/Part_04#383",
    reason: "Size/variant question answered as warehouse stock check",
  },
  {
    key: "الملف_03/Part_01#304",
    reason: "Change-of-mind after booking answered as new appointment booking",
  },
  {
    key: "الملف_03/Part_04#348",
    reason: "Change-of-mind after purchase answered as new appointment booking",
  },
  {
    key: "الملف_03/Part_04#489",
    reason: "Property size/variant question answered as warehouse stock",
  },
  {
    key: "الملف_03/Part_05#304",
    reason: "Current-price question answered as preparation duration",
  },
  {
    key: "الملف_04/Part_03#176",
    reason: "Color/shape variant question answered as warehouse stock",
  },
  {
    key: "الملف_04/Part_04#1",
    reason: "Restaurant size/variant question answered as warehouse stock",
  },
  {
    key: "الملف_05/Part_02#392",
    reason: "Change-of-mind after ticket purchase answered as new appointment booking",
  },
  {
    key: "الملف_05/Part_04#16",
    reason: "Gift-options question answered as stock availability only",
  },
  {
    key: "الملف_05/Part_04#383",
    reason: "Salon size/variant question answered as warehouse stock",
  },
  {
    key: "الملف_05/Part_05#421",
    reason: "Color/shape variant question answered as warehouse stock",
  },
  {
    key: "الملف_06/Part_01#351",
    reason: "Current-price question answered as preparation duration",
  },
  {
    key: "الملف_07/Part_02#48",
    reason: "Current-price question answered as preparation duration",
  },
  {
    key: "الملف_07/Part_03#222",
    reason: "Color/shape variant question answered as warehouse stock",
  },
  {
    key: "الملف_07/Part_05#220",
    reason: "Size/variant question answered as warehouse stock",
  },
];

const GENERIC_A1 = /أراجع سؤالك عن .+ من المعلومات المتاحة\.?$/;
const GENERIC_A2 = /إذا الجواب يعتمد على حالة حيّة أتحقق قبل ما أأكد/;
const GENERIC_A3 = /^ما أزيد تفاصيل غير مدعومة/;

function genericHits(rec) {
  return [rec.answer_1, rec.answer_2, rec.answer_3].filter(
    (a) => GENERIC_A1.test(a) || GENERIC_A2.test(a) || GENERIC_A3.test(a)
  ).length;
}

function hashFile(p) {
  return crypto.createHash("sha256").update(fs.readFileSync(p)).digest("hex");
}

function escapeXml(s) {
  return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

function writeDocx(records, outPath) {
  let body = "";
  for (const r of records) {
    body += `<w:p><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">${escapeXml(r.question)}</w:t></w:r></w:p>`;
    body += `<w:p><w:r><w:t xml:space="preserve">Intent: ${escapeXml(r.intent)} | Domain: ${escapeXml(r.domain)} | Strategy: ${escapeXml(r.response_strategy)}</w:t></w:r></w:p>`;
    body += `<w:p><w:r><w:t xml:space="preserve">Decision: ${escapeXml(r.decision_behavior)}</w:t></w:r></w:p>`;
    body += `<w:p><w:r><w:t xml:space="preserve">Verification: ${escapeXml(r.verification_behavior)} | Clarification: ${escapeXml(r.clarification_behavior)}</w:t></w:r></w:p>`;
    body += `<w:p><w:r><w:t xml:space="preserve">جواب 1: ${escapeXml(r.answer_1)}</w:t></w:r></w:p>`;
    body += `<w:p><w:r><w:t xml:space="preserve">جواب 2: ${escapeXml(r.answer_2)}</w:t></w:r></w:p>`;
    body += `<w:p><w:r><w:t xml:space="preserve">جواب 3: ${escapeXml(r.answer_3)}</w:t></w:r></w:p>`;
    body += `<w:p><w:r><w:t>---</w:t></w:r></w:p>`;
  }
  const documentXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>${body}<w:sectPr/></w:body></w:document>`;
  const contentTypes = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>`;
  const rels = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>`;
  const zip = new AdmZip();
  zip.addFile("[Content_Types].xml", Buffer.from(contentTypes));
  zip.addFile("_rels/.rels", Buffer.from(rels));
  zip.addFile("word/document.xml", Buffer.from(documentXml, "utf8"));
  zip.writeZip(outPath);
}

function sampleIndices(n, k) {
  if (n <= k) return [...Array(n).keys()];
  return Array.from({ length: k }, (_, i) => Math.floor((i * n) / k));
}

function main() {
  const cleanHash = hashFile(CLEAN);
  const p01Hash = fs.existsSync(P01) ? hashFile(P01) : null;
  const findingsHash = hashFile(path.join(BENCH, "findings.jsonl"));
  const sampledHash = hashFile(path.join(BENCH, "sampled_records.jsonl"));

  const p01N = fs.readFileSync(P01, "utf8").trim().split(/\n/).length;
  const cleanLines = fs.readFileSync(CLEAN, "utf8").replace(/^\uFEFF/, "").trim().split(/\n/);
  const cleanN = cleanLines.length;
  const combinedN = p01N + cleanN;
  const combinedA = combinedN * 3;
  if (combinedN !== 23315) throw new Error(`Combined source ${combinedN} != 23315`);
  if (combinedA !== 69945) throw new Error(`Combined answers ${combinedA} != 69945`);
  if (cleanN !== 22819) throw new Error(`Parts 02–50 source ${cleanN} != 22819`);

  const retainedSum = PARTS_02_50.reduce((s, x) => s + x[1], 0);
  if (retainedSum !== cleanN) throw new Error(`Slice sum ${retainedSum} != ${cleanN}`);

  const findings = fs
    .readFileSync(path.join(BENCH, "findings.jsonl"), "utf8")
    .trim()
    .split(/\n/)
    .map(JSON.parse);

  const failedGiKeys = new Set();
  for (const f of findings) {
    if (
      f.labeled_intent === "general_inquiry" &&
      f.severity === "P1" &&
      (f.code === "generic_reusable_answer" || f.code === "does_not_answer")
    ) {
      failedGiKeys.add(`${f.part}#${f.id}`);
    }
  }

  const p1DeleteMap = new Map(P1_SEMANTIC_DELETE.map((x) => [x.key, x.reason]));
  const p1Reviewed = 44;

  const records = [];
  let offset = 0;
  for (const [part, n] of PARTS_02_50) {
    for (let i = 0; i < n; i++) {
      const line = cleanLines[offset + i];
      records.push({ part, line, rec: JSON.parse(line) });
    }
    offset += n;
  }

  const deleted = [];
  const retained = [];
  const giSource = records.filter((x) => x.rec.intent === "general_inquiry").length;
  if (giSource !== 303) throw new Error(`GI in cleaned ${giSource} != 303`);

  const seenDelete = new Set();
  for (const item of records) {
    const key = `${item.part}#${item.rec.id}`;
    if (item.rec.intent === "general_inquiry" && failedGiKeys.has(key)) {
      if (seenDelete.has(key)) throw new Error(`dup delete ${key}`);
      seenDelete.add(key);
      deleted.push({
        id: item.rec.id,
        part: item.part,
        question: item.rec.question,
        intent: item.rec.intent,
        domain: item.rec.domain,
        deletion_reason: "GI_GENERIC_FALLBACK",
        source_finding: "benchmark generic_reusable_answer / does_not_answer (failed GI cohort)",
        severity: "P1",
      });
      continue;
    }
    if (p1DeleteMap.has(key)) {
      if (seenDelete.has(key)) throw new Error(`dup delete ${key}`);
      seenDelete.add(key);
      deleted.push({
        id: item.rec.id,
        part: item.part,
        question: item.rec.question,
        intent: item.rec.intent,
        domain: item.rec.domain,
        deletion_reason: "P1_SEMANTIC_FAILURE",
        source_finding: `core P1: ${p1DeleteMap.get(key)}`,
        severity: "P1",
      });
      continue;
    }
    retained.push(item);
  }

  const giDeleted = deleted.filter((d) => d.deletion_reason === "GI_GENERIC_FALLBACK").length;
  const p1Deleted = deleted.filter((d) => d.deletion_reason === "P1_SEMANTIC_FAILURE").length;
  if (giDeleted !== failedGiKeys.size) {
    throw new Error(`GI deleted ${giDeleted} != failed keys ${failedGiKeys.size}`);
  }
  if (p1Deleted !== P1_SEMANTIC_DELETE.length) {
    throw new Error(`P1 deleted ${p1Deleted} != listed ${P1_SEMANTIC_DELETE.length}`);
  }

  fs.mkdirSync(OUT, { recursive: true });
  const jsonlPath = path.join(OUT, "behavioral_dataset.jsonl");
  const docxPath = path.join(OUT, "behavioral_dataset.docx");
  const manifestPath = path.join(OUT, "deletion_manifest.jsonl");
  const reportPath = path.join(OUT, "final_cleanup_report.txt");

  fs.writeFileSync(jsonlPath, retained.map((x) => x.line).join("\n") + "\n", "utf8");
  fs.writeFileSync(manifestPath, deleted.map((d) => JSON.stringify(d)).join("\n") + "\n", "utf8");
  writeDocx(
    retained.map((x) => x.rec),
    docxPath
  );

  const outLines = fs.readFileSync(jsonlPath, "utf8").trim().split(/\n/);
  const checks = {
    retained_byte_match: true,
    three_answers: true,
    ids_unique_within_part: true,
    composite_unique: true,
    deleted_absent: true,
    no_failed_gi: true,
    no_p1_deletes: true,
    no_fallback_triple: 0,
    remaining_gi: 0,
    docx_q: 0,
  };
  const byPartIds = {};
  const composite = new Set();
  const deletedKeys = new Set(deleted.map((d) => `${d.part}#${d.id}`));
  const p1Keys = new Set(P1_SEMANTIC_DELETE.map((x) => x.key));

  outLines.forEach((line, i) => {
    if (line !== retained[i].line) checks.retained_byte_match = false;
    const rec = JSON.parse(line);
    const part = retained[i].part;
    const key = `${part}#${rec.id}`;
    if (!rec.answer_1 || !rec.answer_2 || !rec.answer_3) checks.three_answers = false;
    if (!byPartIds[part]) byPartIds[part] = new Set();
    if (byPartIds[part].has(rec.id)) checks.ids_unique_within_part = false;
    byPartIds[part].add(rec.id);
    if (composite.has(key)) checks.composite_unique = false;
    composite.add(key);
    if (deletedKeys.has(key)) checks.deleted_absent = false;
    if (failedGiKeys.has(key) && rec.intent === "general_inquiry") checks.no_failed_gi = false;
    if (p1Keys.has(key)) checks.no_p1_deletes = false;
    if (genericHits(rec) >= 2) checks.no_fallback_triple++;
    if (rec.intent === "general_inquiry") checks.remaining_gi++;
  });
  for (const d of deleted) {
    if (composite.has(`${d.part}#${d.id}`)) checks.deleted_absent = false;
  }

  const xml = new AdmZip(docxPath).readAsText("word/document.xml");
  checks.docx_q = (xml.match(/<w:b\/>/g) || []).length;
  const docxOk = checks.docx_q === outLines.length;

  const nRet = outLines.length;
  const inspectIdx = sampleIndices(nRet, 100);
  const inspect = inspectIdx.map((i) => {
    const src = retained[i];
    const rec = JSON.parse(outLines[i]);
    const unchanged =
      outLines[i] === src.line &&
      rec.question === src.rec.question &&
      rec.intent === src.rec.intent &&
      rec.domain === src.rec.domain &&
      rec.response_strategy === src.rec.response_strategy &&
      rec.decision_behavior === src.rec.decision_behavior &&
      rec.verification_behavior === src.rec.verification_behavior &&
      rec.clarification_behavior === src.rec.clarification_behavior &&
      rec.answer_1 === src.rec.answer_1 &&
      rec.answer_2 === src.rec.answer_2 &&
      rec.answer_3 === src.rec.answer_3;
    return { part: src.part, id: rec.id, ok: unchanged };
  });
  const inspectFail = inspect.filter((x) => !x.ok);

  if (hashFile(CLEAN) !== cleanHash) throw new Error("Behavioral_Dataset_Cleaned mutated");
  if (p01Hash && hashFile(P01) !== p01Hash) throw new Error("Part 01 mutated");
  if (hashFile(path.join(BENCH, "findings.jsonl")) !== findingsHash) throw new Error("findings mutated");
  if (hashFile(path.join(BENCH, "sampled_records.jsonl")) !== sampledHash) throw new Error("sampled mutated");

  const perPart = [];
  for (const [part] of PARTS_02_50) {
    const d = deleted.filter((x) => x.part === part).length;
    const r = retained.filter((x) => x.part === part).length;
    perPart.push({ part, deleted: d, retained: r });
  }
  const byReason = {};
  for (const d of deleted) byReason[d.deletion_reason] = (byReason[d.deletion_reason] || 0) + 1;

  const inputParts = cleanN;
  const inputCombined = combinedN;
  const deletedN = deleted.length;
  const retainedN = nRet;
  const remainingAnswers = retainedN * 3;
  const delPctParts = ((deletedN / inputParts) * 100).toFixed(2);
  const delPctCombined = ((deletedN / inputCombined) * 100).toFixed(2);

  const p1RetainedNotes = [
    "unnecessary_clarification only (A1/A2 still answer): 10 records retained",
    "pickup answers valid despite possible return-policy overlap: الملف_08/Part_01 id=419 retained",
    "price question labeled preparation_time but answers actually quote price: الملف_06/Part_05 id=369 and الملف_07/Part_01 id=339 retained",
  ];

  const validationPass =
    combinedN === 23315 &&
    deletedN + retainedN === inputParts &&
    giDeleted === failedGiKeys.size &&
    p1Deleted === 15 &&
    checks.retained_byte_match &&
    checks.three_answers &&
    checks.ids_unique_within_part &&
    checks.composite_unique &&
    checks.deleted_absent &&
    checks.no_failed_gi &&
    checks.no_p1_deletes &&
    checks.no_fallback_triple === 0 &&
    docxOk &&
    inspectFail.length === 0;

  const report = [];
  report.push("Behavioral Dataset Final Clean — deletion-only (Parts 02–50)");
  report.push("No records rewritten. Behavioral_Dataset_Cleaned untouched. Part 01 untouched. No model called.");
  report.push("");
  report.push("1. Input record count");
  report.push(`- Combined (Part 01 + Parts 02–50): ${inputCombined}`);
  report.push(`- Parts 02–50 source (Behavioral_Dataset_Cleaned): ${inputParts}`);
  report.push(`- Part 01 (not in this output): ${p01N}`);
  report.push("");
  report.push("2. Input answer count");
  report.push(`- Combined: ${combinedA}`);
  report.push(`- Parts 02–50: ${inputParts * 3}`);
  report.push("");
  report.push("3. GI deletion count");
  report.push(`- Remaining GI in source Parts 02–50: 303`);
  report.push(`- Failed GI cohort (benchmark generic_reusable_answer / does_not_answer): ${failedGiKeys.size}`);
  report.push(`- GI deleted: ${giDeleted}`);
  report.push(`- GI retained (question-specific answers; not the failed generic-line cohort): ${303 - giDeleted}`);
  report.push("- Did not delete merely because intent=general_inquiry.");
  report.push("");
  report.push("4. P1 candidates reviewed");
  report.push(`- Core sample P1 records reviewed: ${p1Reviewed}`);
  report.push(`- Of those, already in failed GI set: ${[...failedGiKeys].filter((k) => findings.some((f) => `${f.part}#${f.id}` === k && f.sample_bucket === "core" && f.labeled_intent === "general_inquiry")).length}`);
  report.push("- Non-GI P1 deleted only when answers enact the wrong behavior.");
  report.push("- Retained borderline P1:");
  for (const n of p1RetainedNotes) report.push(`  - ${n}`);
  report.push("");
  report.push("5. P1 deletion count");
  report.push(`- ${p1Deleted}`);
  report.push("");
  report.push("6. Total deleted");
  report.push(`- ${deletedN} (GI_GENERIC_FALLBACK ${giDeleted} + P1_SEMANTIC_FAILURE ${p1Deleted})`);
  report.push("");
  report.push("7. Total retained");
  report.push(`- Parts 02–50 output: ${retainedN}`);
  report.push(`- Combined-equivalent if Part 01 still held aside: ${retainedN + p01N}`);
  report.push("");
  report.push("8. Remaining answers");
  report.push(`- ${remainingAnswers}`);
  report.push("");
  report.push("9. Deletion percentage");
  report.push(`- Of Parts 02–50 source: ${delPctParts}%`);
  report.push(`- Of combined 23,315 inventory: ${delPctCombined}%`);
  report.push("");
  report.push("10. Remaining general_inquiry count");
  report.push(`- ${checks.remaining_gi} (in this Parts 02–50 output)`);
  report.push("");
  report.push("11. Remaining confirmed generic fallback count");
  report.push(`- fallback triples (2+ generic lines): ${checks.no_fallback_triple}`);
  report.push("");
  report.push("12. Per-Part deletion counts");
  for (const p of perPart) {
    report.push(`- ${p.part}: deleted=${p.deleted} retained=${p.retained}`);
  }
  report.push("");
  report.push("Deleted by reason");
  for (const [r, n] of Object.entries(byReason)) report.push(`- ${r}: ${n}`);
  report.push("");
  report.push("13. 100-record retention integrity result");
  report.push("- Method: i_k = floor(k * N / 100) for k=0..99");
  report.push(`- inspected: ${inspect.length}`);
  report.push(`- unchanged (question/intent/domain/strategy/behavior/answers): ${inspect.filter((x) => x.ok).length}`);
  report.push(`- failed: ${inspectFail.length}`);
  report.push(`- result: ${inspectFail.length === 0 ? "PASS" : "FAIL"}`);
  report.push("");
  report.push("14. Validation result");
  report.push(`- combined source 23,315 / 69,945: PASS`);
  report.push(`- deleted + retained = Parts 02–50 source: ${deletedN + retainedN === inputParts ? "PASS" : "FAIL"}`);
  report.push(`- retained lines byte-for-byte = source lines: ${checks.retained_byte_match ? "PASS" : "FAIL"}`);
  report.push(`- every retained record has 3 answers: ${checks.three_answers ? "PASS" : "FAIL"}`);
  report.push(`- IDs unique within Part; (part,id) unique: ${checks.ids_unique_within_part && checks.composite_unique ? "PASS" : "FAIL"}`);
  report.push(`- deleted records absent: ${checks.deleted_absent ? "PASS" : "FAIL"}`);
  report.push(`- no retained failed-GI keys: ${checks.no_failed_gi ? "PASS" : "FAIL"}`);
  report.push(`- no retained approved P1 deletes: ${checks.no_p1_deletes ? "PASS" : "FAIL"}`);
  report.push(`- no generic fallback triple remains: ${checks.no_fallback_triple === 0 ? "PASS" : "FAIL"}`);
  report.push(`- no new generic answers created (deletion-only): PASS`);
  report.push(`- DOCX questions = retained: ${docxOk ? "PASS" : "FAIL"} (${checks.docx_q})`);
  report.push(`- Part 01 hash unchanged: PASS`);
  report.push(`- Behavioral_Dataset_Cleaned hash unchanged: PASS`);
  report.push(`- Benchmark findings/sampled hashes unchanged: PASS`);
  report.push(`- 100-record integrity: ${inspectFail.length === 0 ? "PASS" : "FAIL"}`);
  report.push(`- overall: ${validationPass ? "PASS" : "FAIL"}`);
  report.push("");
  report.push("Outputs");
  report.push(`- ${jsonlPath}`);
  report.push(`- ${docxPath}`);
  report.push(`- ${manifestPath}`);
  report.push(`- ${reportPath}`);
  report.push("");
  report.push("Note: output is Parts 02–50 only. Part 01 remains in Part_01_Cleaned/ and was not copied here.");

  fs.writeFileSync(reportPath, report.join("\n"), "utf8");
  console.log(
    JSON.stringify(
      {
        combinedN,
        parts0250: inputParts,
        giDeleted,
        p1Deleted,
        deletedN,
        retainedN,
        remainingAnswers,
        remainingGi: checks.remaining_gi,
        fallback: checks.no_fallback_triple,
        validationPass,
        inspectFail: inspectFail.length,
      },
      null,
      2
    )
  );
}

main();
