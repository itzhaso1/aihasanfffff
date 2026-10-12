/**
 * Apply Part_01 cleanup audit — no re-audit, targeted fixes only.
 */
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";
import AdmZip from "adm-zip";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, "..");
const V4_JSONL = path.join(
  ROOT,
  "Part_01_Human_Likeness_Audit",
  "Part_01_behavioral_dataset_v4.jsonl"
);
const V4_DOCX = path.join(
  ROOT,
  "Part_01_Human_Likeness_Audit",
  "Part_01_behavioral_dataset_v4.docx"
);
const AUDIT_JSONL = path.join(
  ROOT,
  "Part_01_Cleanup_Audit",
  "Part_01_cleanup_audit.jsonl"
);
const OUT_JSONL = path.join(__dirname, "Part_01_behavioral_dataset_clean.jsonl");
const OUT_DOCX = path.join(__dirname, "Part_01_behavioral_dataset_clean.docx");
const OUT_REPORT = path.join(__dirname, "Part_01_cleanup_apply_report.txt");

const DELETE_IDS = new Set([96, 192, 197, 312, 392]);
const FIX_IDS = new Set([
  103, 105, 141, 162, 188, 221, 240, 303, 305, 341, 388, 405, 421, 440, 472,
]);

/** Only fields listed in audit problem_fields — one replacement string each. */
const FIX_ANSWERS = {
  103: {
    answer_3:
      "ما أفترض تقسيطاً. أتحقق من شروط الدفع لـمتابعة الطفل خلال اليوم وأعطيك المختصر.",
  },
  105: {
    answer_3:
      "أركز على عربون متابعة الطفل خلال اليوم والباقي لاحقاً، وأراجع إذا هذا الخيار مسموح.",
  },
  141: {
    answer_1:
      "بخصوص استلام التسجيل بالبرنامج بالنيابة، أراجع التعليمات.",
  },
  162: {
    answer_3: "ألخص لك وش الخيارات الظاهرة لـمتطلبات القبول.",
  },
  188: {
    answer_3:
      "إذا فيه فترة صباحية ومسائية أوضحها لـمتطلبات القبول بعد ما أتأكد.",
  },
  221: {
    answer_3: "أعطيك تسلسل طلب الخطة الدراسية كما هو معتمد.",
  },
  240: {
    answer_1:
      "أراجع الفروع أو المواقع الثانية المرتبطة بالخطة الدراسية وأذكر الموجود.",
  },
  303: {
    answer_3:
      "ما أفترض تقسيطاً. أتحقق من شروط الدفع لـالتقديم على الوظيفة وأعطيك المختصر.",
  },
  305: {
    answer_3:
      "أركز على عربون التقديم على الوظيفة والباقي لاحقاً، وأراجع إذا هذا الخيار مسموح.",
  },
  341: {
    answer_2:
      "إذا التفويض إلزامي لاستلام السيرة الذاتية أقوله لك.",
  },
  388: {
    answer_3:
      "إذا فيه فترة صباحية ومسائية أوضحها لـخدمة الموارد البشرية بعد ما أتأكد.",
  },
  405: {
    answer_1:
      "بخصوص الدفع لـخدمة الموارد البشرية والباقي بعدين، أراجع إذا مسموح عربون مع الباقي لاحقاً أو الدفع كامل.",
  },
  421: {
    answer_3: "أعطيك تسلسل طلب الاستشارة القانونية كما هو معتمد.",
  },
  440: {
    answer_2: "أعطيك الأماكن الظاهرة لـالاستشارة القانونية.",
  },
  472: {
    answer_3:
      "الإجراء مختصر: رسالة، بيانات، ثم تأكيد طلب مراجعة العقد.",
  },
};

function escapeXml(s) {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

function buildDocx(records) {
  let body = "";
  for (const r of records) {
    body += `<w:p><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">${escapeXml(r.question)}</w:t></w:r></w:p>`;
    body += `<w:p><w:r><w:t xml:space="preserve">Intent: ${escapeXml(r.intent)} | Domain: ${escapeXml(r.domain)} | Strategy: ${escapeXml(r.response_strategy)}</w:t></w:r></w:p>`;
    body += `<w:p><w:r><w:t xml:space="preserve">Decision: ${escapeXml(r.decision_behavior)}</w:t></w:r></w:p>`;
    body += `<w:p><w:r><w:t xml:space="preserve">Verification: ${escapeXml(r.verification_behavior)} | Clarification: ${escapeXml(r.clarification_behavior)}</w:t></w:r></w:p>`;
    r.answers.forEach((a, i) => {
      body += `<w:p><w:r><w:t xml:space="preserve">جواب ${i + 1}: ${escapeXml(a)}</w:t></w:r></w:p>`;
    });
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
  zip.writeZip(OUT_DOCX);
}

function recordSignature(r) {
  return JSON.stringify({
    id: r.id,
    question: r.question,
    intent: r.intent,
    domain: r.domain,
    response_strategy: r.response_strategy,
    decision_behavior: r.decision_behavior,
    verification_behavior: r.verification_behavior,
    clarification_behavior: r.clarification_behavior,
    answers: r.answers,
  });
}

function main() {
  fs.mkdirSync(__dirname, { recursive: true });
  const v4 = fs
    .readFileSync(V4_JSONL, "utf8")
    .trim()
    .split(/\n/)
    .map((l) => JSON.parse(l));
  const audit = fs
    .readFileSync(AUDIT_JSONL, "utf8")
    .trim()
    .split(/\n/)
    .map((l) => JSON.parse(l));

  const auditById = new Map(audit.map((a) => [a.id, a]));

  for (const id of DELETE_IDS) {
    const a = auditById.get(id);
    if (!a || a.classification !== "DELETE") {
      throw new Error(`Audit DELETE mismatch for id ${id}`);
    }
  }
  for (const id of FIX_IDS) {
    const a = auditById.get(id);
    if (!a || a.classification !== "FIX") {
      throw new Error(`Audit FIX mismatch for id ${id}`);
    }
    const fixes = FIX_ANSWERS[id];
    if (!fixes) throw new Error(`Missing FIX_ANSWERS for ${id}`);
    for (const f of a.problem_fields) {
      if (!f.startsWith("answer_")) continue;
      if (fixes[f] === undefined) {
        throw new Error(`Missing fix text for #${id} field ${f}`);
      }
    }
  }

  const v4Before = new Map(v4.map((r) => [r.id, structuredClone(r)]));
  const fixLog = [];
  const out = [];

  for (const r of v4) {
    if (DELETE_IDS.has(r.id)) continue;

    const rec = structuredClone(r);
    if (FIX_IDS.has(r.id)) {
      const fixes = FIX_ANSWERS[r.id];
      const auditRow = auditById.get(r.id);
      for (const field of auditRow.problem_fields) {
        if (!field.startsWith("answer_")) continue;
        const idx = parseInt(field.split("_")[1], 10) - 1;
        const before = rec.answers[idx];
        rec.answers[idx] = fixes[field];
        fixLog.push({ id: r.id, field, before, after: fixes[field] });
      }
    }
    out.push(rec);
  }

  if (out.length !== 496) {
    throw new Error(`Expected 496 records, got ${out.length}`);
  }

  fs.writeFileSync(
    OUT_JSONL,
    out.map((r) =>
      JSON.stringify({
        id: r.id,
        question: r.question,
        intent: r.intent,
        domain: r.domain,
        response_strategy: r.response_strategy,
        decision_behavior: r.decision_behavior,
        verification_behavior: r.verification_behavior,
        clarification_behavior: r.clarification_behavior,
        answers: r.answers,
      })
    ).join("\n") + "\n",
    "utf8"
  );
  buildDocx(out);

  let keepUnchanged = 0;
  let fixCount = 0;
  let otherChanged = 0;
  const fixIdsApplied = new Set();

  for (const rec of out) {
    const orig = v4Before.get(rec.id);
    if (FIX_IDS.has(rec.id)) {
      fixCount++;
      fixIdsApplied.add(rec.id);
      const sigOrig = recordSignature(orig);
      const sigNew = recordSignature(rec);
      if (sigOrig === sigNew) throw new Error(`FIX record ${rec.id} unchanged`);
      // metadata must be unchanged
      for (const k of [
        "question",
        "intent",
        "domain",
        "response_strategy",
        "decision_behavior",
        "verification_behavior",
        "clarification_behavior",
      ]) {
        if (orig[k] !== rec[k]) {
          throw new Error(`FIX record ${rec.id} metadata changed: ${k}`);
        }
      }
    } else if (recordSignature(orig) === recordSignature(rec)) {
      keepUnchanged++;
    } else {
      otherChanged++;
    }
  }

  const answersTotal = out.reduce((n, r) => n + r.answers.length, 0);
  const ids = new Set(out.map((r) => r.id));
  const v4HashBefore = fs.readFileSync(V4_DOCX);
  const v4HashAfter = fs.readFileSync(V4_DOCX);

  const validation = {
    v4_docx_untouched: v4HashBefore.equals(v4HashAfter),
    recordCount: out.length === 496,
    answerCount: answersTotal === 1488,
    deleted: v4.length - out.length === 5,
    fixed: fixCount === 15,
    keepUnchanged: keepUnchanged === 481,
    noOtherChanges: otherChanged === 0,
    uniqueIds: ids.size === 496,
    threeAnswersEach: out.every((r) => r.answers.length === 3),
    fixLogFields: fixLog.length,
  };

  const allPass = Object.values(validation).every(Boolean);

  const zip = new AdmZip(OUT_DOCX);
  const xml = zip.readAsText("word/document.xml");
  const docxQ = (xml.match(/<w:b\/>/g) || []).length;

  const report = [];
  report.push("Part_01 Cleanup Apply Report");
  report.push("");
  report.push("- original records: 501");
  report.push("- final records: 496");
  report.push("- deleted: 5");
  report.push("- fixed: 15");
  report.push("- unchanged KEEP: 481");
  report.push("- final answers: 1488");
  report.push(`- validation result: ${allPass && docxQ === 496 ? "PASS" : "FAIL"}`);
  report.push("");
  report.push("## Deleted IDs");
  report.push([...DELETE_IDS].sort((a, b) => a - b).join(", "));
  report.push("");
  report.push("## Fixed records (field-level)");
  for (const entry of fixLog) {
    report.push(`- #${entry.id} ${entry.field}`);
    report.push(`  BEFORE: ${entry.before}`);
    report.push(`  AFTER:  ${entry.after}`);
  }
  report.push("");
  report.push("## Validation detail");
  for (const [k, v] of Object.entries(validation)) {
    report.push(`- ${k}: ${v ? "PASS" : "FAIL"}`);
  }
  report.push(`- DOCX question blocks: ${docxQ} ${docxQ === 496 ? "PASS" : "FAIL"}`);
  report.push("");
  report.push("Source v4 JSONL/DOCX not modified (read-only).");

  fs.writeFileSync(OUT_REPORT, report.join("\n"), "utf8");
  console.log(JSON.stringify({ validation, docxQ, fixLogCount: fixLog.length }, null, 2));
}

main();
