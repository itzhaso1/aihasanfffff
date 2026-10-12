# Phase 9A — ZATCA Cryptographic Specification Reconciliation

**Status:** REVIEW REQUIRED  
**Date:** 10 September 2026  
**Scope:** Specification reconciliation only. No production cryptographic signing. No change to Phase 7 security chain, Phase 8 QR Tags 1–6, CSID provisioning, CSR submission, FATOORA HTTP, or XAdES production emission.  
**HASEM production impact of this phase:** documentation only.

This document is the authoritative input for a future production cryptographic-stamping implementation. Where official sources conflict and precedence cannot be established from those sources, the field is marked **UNRESOLVED**. Unresolved production-critical fields must not be implemented.

---

## 0. How this document was produced

1. Official PDFs were retrieved from `zatca.gov.sa` (and the English Developer Portal / Guideline URLs published there).
2. Text was extracted from those PDFs. Regulatory conclusions cite document title, version/date, URL, section, and page.
3. Hex / Base64 examples were reconstructed with Python’s standard library (`base64` only). HASEM production code was not used to decode examples.
4. Blogs, Stack Overflow, GitHub SDKs, third-party ZATCA libraries, tutorials, and vendor docs were **not** used to resolve conflicts.
5. ETSI / W3C / OASIS text is used only where ZATCA explicitly incorporates those standards, and only to identify what those incorporated standards define — not to pick a winner among conflicting ZATCA documents.

---

## 1. Document precedence (established from the sources themselves)

These documents serve **different purposes**. A later publication date does not automatically win.

| Rank | Document | Role | What it may decide | What it may not silently override |
| --- | --- | --- | --- | --- |
| 1 | E-Invoicing Implementation Resolution (Arabic official; English translation unofficial) | Legal obligation | Field *meanings* in Annex (2); that a cryptographic stamp / CSID / QR exists; simplified vs standard | Byte encoding of QR tags, curve OID, XAdES element list |
| 2 | Security Features Implementation Standards v1.2 (19 May 2023) | Normative security / crypto / QR encoding | Cryptographic stamp structure, X.509 illustrative profile, QR TLV rules, PIH transform | Educational openssl examples; FAQ sample Java |
| 3 | Electronic Invoice XML Implementation Standard v1.2 (19 May 2023) | Normative UBL / BR-KSA | Invoice hash *method*, PIH first-value, stamp UBL IDs, when stamp is mandatory | QR tag byte representation (explicitly defers to Security Features) |
| 4 | E-invoicing Detailed Technical Guideline, Version 2 FATOORA, Nov 2022 | Educational / process walkthrough | Onboarding steps, clearance/reporting flow, SDK-oriented signing walkthrough, worked hex examples | Cannot resolve a conflict against (2) or (3). Recommended reading list points *to* (2) and (3). |
| 5 | Developer Portal User Manual, Version 2, June 2022 | Educational / SDK / sandbox | Compliance vs Production CSID APIs, FAQ, openssl snippets | FAQ sample code that does not implement the encoding it describes |
| 6 | Guide to Developed FATOORA Compliant QR Code, 18 Nov 2021 | Educational QR TLV primer | Tags 1–5 worked example | Page 17 states it is **not binding** and **not a legal reference**. It cites Security Features dated 2021-05-28. |

**Explicit deferrals found in the sources:**

- XML Implementation Standard v1.2, **BR-KSA-27**, pages 54–55: the QR code (KSA-14) “must be base64Binary. Please refer to the Security Features Implementation Standards for more details.”
- Detailed Technical Guideline, page 8: users “should also go through” the Resolution, Data Dictionary, XML Implementation Standards, and Security Features.
- Developer Portal Manual, §1.1.4, page 7: recommended reading is XML Implementation Standards, Security Features, Data Dictionary, Resolution — i.e. the Portal is not the cryptographic source of truth.
- QRCodeCreation.pdf, page 17: “not considered in any way binding to ZATCA… cannot be relied upon as a legal reference.”
- Security Features v1.2, §2.2.2, page 13: the X.509 profile “is provided as an **illustrative** profile”; “the **final** certificate profile is going to be published by ZATCA in connection with its CA(s) service as part of its CP/CPS.”
- Security Features v1.2, §2.3.1, page 18: XAdES section is “**guidance**… minimum signature components”; taxpayers “shall follow the detailed standard specifications” of ETSI EN 319 132-1 and W3C XMLDSig.

**v1.1 vs v1.2 of Security Features:** v1.2 changelog (page 1) states the only v1.2 change is “Elaborated on QR code Tag 5 VAT total.” The Tag 6 “length is 32 bytes” note already exists in v1.1 (24 June 2022), page 25. v1.2 supersedes v1.1 for Tag 5 wording; it does **not** introduce a new Tag 6 rule.

**Conclusion used below:** when Resolution / Security Features / XML Standard agree, the field is treated as resolved. When Guideline or Portal *examples* contradict Security Features *rules*, the conflict is recorded. Educational documents are **not** used to override Security Features unless Security Features is silent *and* the educational text is internally consistent. If even that bar fails, the field stays **UNRESOLVED**.

---

## 2. Source inventory

| ID | Document | Version / date | Official URL | Relevant sections / pages |
| --- | --- | --- | --- | --- |
| RES | Controls, Requirements, Technical Specifications and Procedural Rules for Implementing the Provisions of the E-Invoicing Regulation (English translation). Disclaimer: Arabic prevails. | English translation dated 2 June 2021 (document header). Enforcement dates 4 Dec 2021 and 1 Jan 2023. | Listed from ZATCA e-invoicing pages; English file used in this research: Implementation Resolution EN translation. Listing hub: https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/Pages/E-Invoice-specifications.aspx | Annex additional requirements pp. 11–14: cryptographic stamp identifier; QR IDs 1–9; prohibited export of stamping keys; disk encryption |
| SEC-1.2 | Security Features Implementation Standards to the E-Invoicing resolution | **Version 1.2, 2023-05-19**. Changelog: v1.0 2021-05-28; v1.1 2022-06-24; v1.2 2023-05-19. | https://zatca.gov.sa/ar/E-Invoicing/SystemsDevelopers/Documents/20230519_ZATCA_Electronic_Invoice_Security_Features_Implementation_Standards_vF.pdf | §1 p.3 (ETSI/W3C incorporation); §2.1 CSID issuance/renewal/revocation pp.6–8; §2.2.1 req. 6–18 pp.11–13; §2.2.2 certificate profile pp.13–17; §2.3.3 XAdES pp.19–22; §3 PIH p.25; §4.1 QR pp.25–26 |
| SEC-1.1 | Same title | **Version 1.1, 2022-06-24** | Prior revision of the same family (compared for supersession). Same listing family as SEC-1.2. | §4.1 p.25 Tag 6 32-byte note already present; QR table p.26 |
| XML-1.2 | Electronic Invoice XML Implementation Standard to the E-Invoicing resolution | **Version 1.2, 2023-05-19**. Changelog p.1: v1.0 2021-05-28; v1.1 2022-06-24; v1.2 2023-05-19. | https://zatca.gov.sa/ar/E-Invoicing/SystemsDevelopers/Documents/20230519_ZATCA_Electronic_Invoice_XML_Implementation_Standard_%20vF.pdf | p.6 KSA rules override EN 16931 override UBL; BR-KSA-26/27 pp.53–55; BR-KSA-28/29/30 p.55; BR-KSA-60/61 pp.57–58 |
| GL | E-invoicing Detailed Technical Guidelines | **Version 2 FATOORA, Nov 2022** | https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Documents/E-invoicing-Detailed-Technical-Guideline.pdf | §1.1.4 p.8 recommended reading; §4.3.2 clearance pp.50–51; §5 signing pp.52–57; §6 QR pp.58–65 including combined hex p.62 |
| PORT | User Manual, Developer Portal Manual | **Version 2, June 2022** (PDF CreationDate 2022-06-23) | https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Documents/DEVELOPER-PORTAL-MANUAL.pdf (also published under ComplianceEnablementToolbox) | §1.1.4 p.7; §2.1 p.8 JDK/secp256k1; CSR/CSID pp.51–52, 77–83; FAQ QR pp.61–62 |
| QR-EDU | Guide to Developed FATOORA Compliant QR Code | **18 Nov 2021** | https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/Documents/QRCodeCreation.pdf | pp.3–4 TLV; p.15 Tags 1–5 example; p.17 non-binding disclaimer. Cites Security Features `20210528_…_vShared.pdf` |
| LIST | E-Invoice specifications (listing page) | Page last update **12 Jan 2026 11:01 AM Saudi Arabia Time** | https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/Pages/E-Invoice-specifications.aspx | Points to XML Standard and Data Dictionary dated 19 May 2023 |
| SEC-PAGE | Security Requirements listing page | Official ZATCA systems-developers security page | https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/Pages/Security-Requirements.aspx | Entry point for Security Features PDF |
| ETSI-XAdES | ETSI EN 319 132-1 XAdES Part 1 (incorporated by SEC-1.2 §1 item 1 and req. 10) | Cited by ZATCA as the XAdES standard; ZATCA does not reprint a version number beyond the EN number | Standard identified by SEC-1.2 page 3 | Baseline B-B; SignedProperties; SigningCertificateV2 |
| W3C-XMLDSIG | W3C Recommendation “XML-Signature Syntax and Processing” (incorporated by SEC-1.2 §1 item 3) | Cited without a 1.0 vs 1.1 version | Standard identified by SEC-1.2 page 3 | ds:SignedInfo, SignatureValue Base64, canonicalization algorithms. ECDSA encoding depends on which XMLDSig edition is meant — ZATCA does not say. |
| RFC5280 | Internet X.509 PKI Certificate and CRL Profile | Cited by SEC-1.2 §2.2.2 | IETF RFC 5280 | X.509 v3 profile that the illustrative CSID certificate “complies with” |
| FIPS186 | FIPS 186 (Digital Signature Standard) | Cited by SEC-1.2 §2.2.1 req. 6 | NIST FIPS 186 | Key-pair generation. Approved NIST curves include P-256; secp256k1 is not a NIST/FIPS named curve. |
| NCS / DSP | NCA National Cryptographic Standards (NCS-1:2020); NCDC Digital Signing Policy v1.1:2020 | Cited by SEC-1.2 §1 p.3 as compliance principles | Named by SEC-1.2; not independently re-litigated here | Context only; they do not name secp256k1 vs P-256 in the ZATCA extract |

OIDs **1.2.840.10045.3.1.7** (NIST P-256 / secp256r1 / prime256v1) and **1.3.132.0.10** (secp256k1) are **never printed as text** in SEC-1.2, XML-1.2, RES, PORT, or QR-EDU. The secp256k1 OID appears only as **bytes** inside the Guideline p.62 Tag 8 example (see §16).

---

## 3. Cryptographic reconciliation matrix

Columns: Question · Claim A · Source A · Claim B · Source B · Conflict? · Precedence analysis · Final conclusion · Confidence · Implementation consequence.

### 3.1 Elliptic curve

| Field | Content |
| --- | --- |
| **Question** | Is the EGS production curve NIST P-256 or secp256k1? Are these two requirements for different artifacts? |
| **Claim A** | Certificate `SubjectPublicKeyInfo` “Public Key Key length: **P-256**”. Algorithms: SHA-256, ECDSA, key length 256. Key generation according to **FIPS 186**. |
| **Source A** | SEC-1.2 §2.2.2 p.14 (`SubjectPublicKeyInfo`); §2.2.1 req. 16 p.13; §2.2.1 req. 6 p.11. No curve OID printed. |
| **Claim B** | `openssl ecparam -name secp256k1`. Portal: “we are generating a pair of ECDSA keys with the **P-256 (secp256k1)** curve.” Portal §2.1: SDK “JDK versions >=11 and <15, to comply with **secp256k1** as per ZATCA security regulations.” Guideline p.62 Tag 8 SPKI contains OID **1.3.132.0.10** (secp256k1), not 1.2.840.10045.3.1.7. |
| **Source B** | GL §5 additional reference p.57; PORT §5.3.2.1 p.82; PORT §2.1 p.8; GL §6.2 combined hex p.62 (independent decode). |
| **Conflict?** | **YES.** NIST P-256 (secp256r1, OID 1.2.840.10045.3.1.7) and secp256k1 (OID 1.3.132.0.10) are different curves. The phrase “P-256 (secp256k1)” is not a valid identification of either curve. |
| **Precedence analysis** | SEC-1.2 is the normative certificate profile, but it is explicitly **illustrative** pending CP/CPS, and it never prints an OID. “P-256” in X.509/NIST usage means NIST P-256, not secp256k1. FIPS 186 key generation also points at NIST curves. Operational ZATCA educational materials and the only official SPKI byte example use secp256k1. The documents do **not** split the requirement (EGS key vs stamp vs QR vs certificate) onto two curves: every artifact is described as one EGS key pair / one CSID. |
| **Final conclusion** | `EGS production curve = UNRESOLVED` |
| **Confidence** | High that a conflict exists. High that “P-256 (secp256k1)” is a mislabel, not a third curve. Low that either curve can be selected from paper alone. |
| **Implementation consequence** | **NO PRODUCTION IMPLEMENTATION SHOULD BE BASED ON THIS FIELD YET.** Do not generate production EGS keys. A future parser may *accept* whichever curve appears in a ZATCA-issued CSID. Test-only secp256k1 (Phase 9 `SigningAlgorithm::TEST_CURVE`) remains a local test profile, not a regulatory decision. |

Per-artifact (all UNRESOLVED, same reason — one key pair, conflicting identification):

| Artifact | Required curve |
| --- | --- |
| EGS key pair | UNRESOLVED |
| Cryptographic stamp (ECDSA) | UNRESOLVED (same key) |
| QR public key (Tag 8) | UNRESOLVED (same key; encoding separately unresolved) |
| Certificate public key | UNRESOLVED (illustrative profile says “P-256”; example SPKI is secp256k1) |

---

### 3.2 Signing input (invoice hash vs XMLDSig SignedInfo)

| Field | Content |
| --- | --- |
| **Question** | Does ECDSA sign the invoice hash, or canonical `ds:SignedInfo`? |
| **Claim A** | XMLDSig/XAdES: canonicalize `ds:SignedInfo` (which contains ≥2 `ds:Reference` digest values), then apply SignatureMethod. First Reference digest = SHA-256 of transformed invoice XML. Second Reference = SignedProperties. `ds:SignatureValue` is the signature of SignedInfo, Base64 [RFC2045]. |
| **Source A** | SEC-1.2 §2.3.3 pp.19–21 items 1, 1.1, 1.2, 1.3, 1.4; W3C XMLDSig as incorporated; ETSI EN 319 132-1 as incorporated. SEC-1.2 §2.2.1 req. 10–12: XAdES enveloped; “the whole XML content except the QR-code data element need to be covered by the signature.” |
| **Claim B** | “Sign the generated invoice hash with ECDSA using the private key” and put that string in `ds:SignatureValue`. |
| **Source B** | GL §5 Step 2 p.53 and Step 6 p.56 (`SignatureValue` ← “Digital Signature from Step 2”). |
| **Conflict?** | **YES.** These are different byte strings. Signing SHA-256(invoice) is not XMLDSig. XMLDSig signs canonical SignedInfo, whose first digest *happens to be* the invoice hash. |
| **Precedence analysis** | SEC-1.2 is the normative stamp format and explicitly requires XAdES/XMLDSig SignedInfo. GL §5 is an SDK-style simplification. That strongly *suggests* Claim A for a conforming XAdES stamp. It does **not** prove that ZATCA’s validator actually verifies SignedInfo rather than the invoice-hash signature the Guideline tells developers to emit. The Guideline’s own SignatureValue examples are ECDSA-SHA256-looking Base64 and are placed in `ds:SignatureValue` without a SignedInfo signing step. |
| **Final conclusion** | **UNRESOLVED** which bytes are the ECDSA input for the production stamp. Conceptual XAdES sequence is documented in §5. Invoice hash *computation* is resolved (see 3.4). |
| **Confidence** | High that the two procedures differ. Medium that a fully conforming XAdES implementation would sign SignedInfo. Low that ZATCA runtime accepts only that. |
| **Implementation consequence** | **NO PRODUCTION IMPLEMENTATION SHOULD BE BASED ON THIS FIELD YET.** Phase 9 test signer signs the Phase 7 32-byte invoice-hash digest; that is a test profile, not XAdES. |

---

### 3.3 Invoice hash (distinct from the stamp)

| Field | Content |
| --- | --- |
| **Question** | Exact invoice-hash byte pipeline |
| **Claim A** | Remove UBLExtensions; remove AdditionalDocumentReference where ID=QR; remove cac:Signature; C14N11; SHA-256 binary; Base64 → digest / PIH. First PIH = Base64(SHA-256 of ASCII `"0"`). |
| **Source A** | XML-1.2 **BR-KSA-26** pp.53–54; SEC-1.2 §3 p.25 (“same transform as is used for the cryptographic stamp… section 2.3.3”); SEC-1.2 §2.3.3 Transforms pp.20–21 (XPath exclude `ext:UBLExtensions`, `cac:Signature`, `cac:AdditionalDocumentReference[cbc:ID='QR']`, then `http://www.w3.org/2006/12/xml-c14n11`). |
| **Claim B** | Same exclusions + “Remove the XML version” then C14N11 + SHA-256 + Base64. |
| **Source B** | GL §5 Step 1 p.52. |
| **Conflict?** | Minor. “Remove the XML version” is Guideline-only. BR-KSA-26 / Security Features do not mention removing the XML declaration as a separate rule; C14N11 already defines declaration handling. |
| **Precedence analysis** | BR-KSA-26 + SEC-1.2 transforms win for the hash method. Guideline extra step is educational. |
| **Final conclusion** | **RESOLVED** (matches Phase 7). First PIH value stated by BR-KSA-26: `NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==` = Base64(SHA-256(`0`)). |
| **Confidence** | High |
| **Implementation consequence** | Do not modify Phase 7. Future XAdES first `ds:DigestValue` must equal this invoice hash. |

---

### 3.4 XAdES profile

| Field | Content |
| --- | --- |
| **Question** | XAdES profile, packaging, SignedInfo, SignedProperties, algorithms |
| **Claim A** | ETSI EN 319 132-1; **enveloped**; level **B-B**; SHA-256 + ECDSA + 256-bit; whole XML except QR; SignedInfo with ≥2 References; SignatureValue Base64 RFC2045; KeyInfo X509Certificate **full chain**; SignedSignatureProperties: SigningTime, **SigningCertificateV2**, SignaturePolicyIdentifier; DataObjectFormat MimeType always `text/xml`; Transforms as in 2.3.3. |
| **Source A** | SEC-1.2 §2.2.1 req. 10, 11, 12, 15, 16 pp.12–13; §2.3.3 pp.19–22. |
| **Claim B** | Guideline uses `xades:SigningCertificate` (not V2), linearizes SignedProperties by “remove the spaces”, and fills SignatureValue from ECDSA(invoice hash). Tag/XPath tables. |
| **Source B** | GL §5 Steps 4–6 pp.54–56. |
| **Conflict?** | **YES** on SigningCertificate vs SigningCertificateV2, on how SignedProperties are hashed, and on what SignatureValue signs. Packaging (enveloped, UBL extension location) is consistent at a high level. |
| **Precedence analysis** | SEC-1.2 names B-B and SigningCertificateV2. Guideline is a simplified walkthrough. Exact XMLDSig SignatureMethod URI is **not stated** in any ZATCA PDF. |
| **Final conclusion** | Profile **XAdES-B-B enveloped** is **RESOLVED as the named target**. Production element-level emission is **BLOCKED** until signing-input and certificate (CSID) are resolved. Future checklist: see §8. |
| **Confidence** | High on the named profile. Low on Guideline-vs-ETSI details the validator will enforce. |
| **Implementation consequence** | Keep `XadesEnvelopedSignature::materialize()` throwing. Do not emit UBLExtensions signature XML. |

---

### 3.5 Signature algorithm / encoding

| Field | Content |
| --- | --- |
| **Question** | Algorithm, hash, curve, SignatureMethod URI, signature byte representation |
| **Claim A** | Hash SHA-256; asymmetric ECDSA; key length 256. SignatureValue “always encoded using base64 [RFC2045]”. XMLDSig SignatureMethod must be an algorithm from XMLDSig [3]. |
| **Source A** | SEC-1.2 §2.2.1 req. 16 p.13; §2.3.3 item 1.2 p.19 and item 1.4 p.21. |
| **Claim B** | IEEE **P1363** `r \|\| s`, 64 bytes for 256-bit curves (example “like secp256k1”). Accompanying Java `extractR` hashes the Base64 payload with SHA-256 and takes bytes `[0,32)` — which is **not** P1363 splitting of `r` and `s`. Guideline Tag 7 example decodes to **DER ECDSA SEQUENCE** (71 bytes, starts `30 45 02 21`). |
| **Source B** | PORT FAQ pp.61–62; GL §6.2 table + p.62 combined hex (independent decode). |
| **Conflict?** | **YES.** DER ASN.1 ECDSA vs IEEE P1363 `r \|\| s`. Portal FAQ text vs Portal FAQ sample code are themselves inconsistent. W3C XMLDSig 1.1 ECDSA (if that were the incorporated edition) uses P1363 in SignatureValue; XMLDSig 2002 does not define ECDSA; RFC 3279 / RFC 4050 use DER `ECDSA-Sig-Value`. ZATCA does not name the XMLDSig edition or a SignatureMethod URI such as `http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256`. |
| **Precedence analysis** | Cannot choose. Security Features requires Base64(signature) but not DER vs P1363. Educational sources disagree with each other and with their own sample code. |
| **Final conclusion** | Algorithm family **ECDSA + SHA-256 + 256-bit** is **RESOLVED**. Curve **UNRESOLVED**. SignatureMethod URI **UNRESOLVED**. Raw signature encoding **UNRESOLVED** (DER vs P1363). |
| **Confidence** | High on SHA-256 + ECDSA + 256-bit. High that encoding is conflicting. |
| **Implementation consequence** | **NO PRODUCTION IMPLEMENTATION SHOULD BE BASED ON DER-vs-P1363 YET.** |

---

### 3.6 QR Tag 7

| Field | Content |
| --- | --- |
| **Question** | Exact Tag 7 value, bytes, serialization |
| **Claim A** | Table: “ECDSA signature of the XML Hash.” TLV values generally UTF-8. |
| **Source A** | SEC-1.2 §4.1 Table 3 p.26; §4.1 order of operations step 2 p.25 (UTF-8 for each value). |
| **Claim B** | XPath = `ds:SignatureValue`. Worked example: Tag `07`, length `0x60` = 96, value = ASCII of Base64 string `MEUCIQD5zxyX…xYY=` which independently decodes to 71-byte DER ECDSA `30 45 02 21 …`. Portal: P1363 64-byte `r \|\| s`. |
| **Source B** | GL §6 p.61 XPath; GL §6.2 + p.62 hex; PORT FAQ p.62. |
| **Conflict?** | **YES.** (1) What is signed (XML hash vs SignedInfo — §3.2). (2) DER-Base64-as-UTF-8 (96 bytes) vs raw P1363 (64 bytes) vs raw DER. (3) Whether Tag 7 TLV value is identical to the XML `ds:SignatureValue` text. |
| **Precedence analysis** | Cannot choose. Security Features names the *meaning* (“signature of the XML Hash”) but not the byte encoding. Guideline example and XPath agree with each other (UTF-8 of SignatureValue Base64 text) and disagree with Portal P1363. |
| **Final conclusion** | **UNRESOLVED.** Recorded observations (not a production choice): Guideline combined hex Tag 7 = UTF-8 bytes of a Base64(DER ECDSA) string, length 96. |
| **Confidence** | High that official sources disagree. |
| **Implementation consequence** | **NO PRODUCTION IMPLEMENTATION SHOULD BE BASED ON THIS FIELD YET.** Phase 9 test Tag 7 (Base64 DER) is test-only. |

**Tag 7 exact value source:** UNRESOLVED (GL: `ds:SignatureValue`; SEC: “ECDSA signature of the XML Hash”; PORT: P1363 blob).  
**Tag 7 exact byte representation:** UNRESOLVED.  
**Tag 7 exact serialization:** UNRESOLVED (UTF-8 of Base64 vs raw 64-byte P1363 vs raw DER). Base64 of the *whole QR* is applied after TLV concatenation (SEC-1.2 §4.1 steps 3–4), not as an extra wrap of Tag 7 alone — that part is resolved.

---

### 3.7 QR Tag 8

| Field | Content |
| --- | --- |
| **Question** | Exact Tag 8 representation (EC point vs SPKI vs X.509 vs 64-byte BLOB) |
| **Claim A** | Resolution: “the public key used to generate the Cryptographic stamp” — EGS public key (simplified) / optional ZATCA platform key (standard). Security Features: “ECDSA public key extracted from the signing private key.” |
| **Source A** | RES Annex (2) ID 8 p.14; SEC-1.2 Table 3 p.26. |
| **Claim B1** | XPath = `ds:X509Certificate` (the certificate, not the raw key). |
| **Source B1** | GL p.61. |
| **Claim B2** | Combined hex: Tag `08`, length `0x58` = 88, value = raw **SPKI DER** `3056301006072a8648ce3d020106052b8104000a03420004` + 64-byte uncompressed point. OID secp256k1. Not an X.509 certificate. |
| **Source B2** | GL p.62 combined hex, independent decode. |
| **Claim B3** | “public key BLOB… 64 bytes (72 bytes including magic number…)”. Compressed public key in openssl snippet. |
| **Source B3** | PORT FAQ p.61; PORT §5.3.2.2 p.82. |
| **Conflict?** | **YES.** Four different artifacts: uncompressed SPKI (88 bytes), raw XY (64), compressed point, X.509 certificate. Guideline XPath vs Guideline hex disagree **inside the same document**. |
| **Precedence analysis** | Cannot choose. Meaning (public key, not the certificate) is aligned across Resolution + Security Features. Encoding is not. |
| **Final conclusion** | **UNRESOLVED.** Independent observation: the only intact official byte example is 88-byte uncompressed secp256k1 SPKI, binary in the TLV value (not Base64 text). |
| **Confidence** | High that sources disagree. High that the p.62 bytes are SPKI, not X.509. |
| **Implementation consequence** | **NO PRODUCTION IMPLEMENTATION SHOULD BE BASED ON THIS FIELD YET.** |

---

### 3.8 QR Tag 9

| Field | Content |
| --- | --- |
| **Question** | Meaning, when required, who signs, encoding, local generation |
| **Claim A** | Simplified tax invoices **and associated notes** only. “Authority’s Portal Cryptographic stamp of the public key of the E-Invoice Solution.” Security Features / QR-EDU / Guideline: ECDSA signature of the cryptographic stamp’s public key **by ZATCA’s technical CA**. |
| **Source A** | RES Annex (2) ID 9 p.14; SEC-1.2 Table 3 p.26; QR-EDU p.4; GL pp.58, 61–63. |
| **Claim B** | “Get PCSID… Decode the PCSID… Copy the value of Signature Algorithm: ecdsa-with-SHA256.” Combined hex after Tag 8: TLV Tag `09`, length `0x48` = 72, value = raw DER ECDSA SEQUENCE `30 46 02 21 …` (independent parse). |
| **Source B** | GL pp.62–63 (steps vs hex). |
| **Conflict?** | Encoding/extraction steps conflict **inside the Guideline** (algorithm *name* vs 72-byte DER). Meaning is consistent: ZATCA CA signature over EGS public key / certificate material. XPath on p.61 points at `X509Certificate`, which is not a CA signature. |
| **Precedence analysis** | Meaning: Resolution + Security Features agree — EXTERNAL ZATCA artifact, simplified + associated notes. Encoding: do not choose between Guideline steps and Guideline hex. Local generation is not authorized. |
| **Final conclusion** | **EXTERNAL ZATCA ARTIFACT.** Cannot be generated locally. Required for simplified invoices and associated credit/debit notes. Independent of the EGS invoice signature (it is the CA’s signature on the EGS public key). Encoding of the TLV value remains **UNRESOLVED** (DER vs other). |
| **Confidence** | High on external/PCSID origin and applicability. Low on exact TLV bytes. |
| **Implementation consequence** | Never generate Tag 9 locally. Future implementation extracts it from Production CSID once ZATCA documents the field unambiguously, or once a real PCSID can be parsed under a later phase. |

---

### 3.9 CSID

| Field | Content |
| --- | --- |
| **Question** | What CSID is; types; how obtained; local generation |
| **Claim A** | Cryptographic Stamp Identifier **is** a digital certificate issued by ZATCA’s technical CA for an EGS unit. Stored on the EGS with the key pair. Issued/renewed/revoked via taxpayer portal. Not generated locally as a ZATCA identity. |
| **Source A** | SEC-1.2 §2.1.1 pp.6–7 (“Cryptographic Stamp Identifier (digital certificate)”); RES p.12. |
| **Claim B** | Two API types: **Compliance CSID** (onboarding; Portal describes it as a self-signed certificate issued by the e-invoicing platform to continue onboarding) and **Production CSID** (onboarding/renewal). Tag 9 uses **PCSID**. CSR (PKCS#10) + OTP. Client ID for OAuth = the digital certificate; Secret issued at onboarding. |
| **Source B** | PORT pp.51–52, 77–78, 82–83; GL p.62 “Get a hold of your device’s PCSID”; SEC-1.2 §5 p.27 OAuth. |
| **Conflict?** | Terminology only (certificate vs identifier). Substantively: CSID **is** the certificate (identifier *of* the stamp, realized as X.509). Multiple types: Compliance vs Production. |
| **Precedence analysis** | Aligned enough to document the dependency. Do not implement onboarding. |
| **Final conclusion** | **RESOLVED as a dependency.** CSID = ZATCA-issued X.509 for the EGS. Production stamping requires Production CSID. Local self-signed certificates are not CSIDs. |
| **Confidence** | High |
| **Implementation consequence** | EXTERNAL DEPENDENCY. No CSR, OTP, or FATOORA HTTP in this phase. |

---

### 3.10 X.509 certificate

| Field | Content |
| --- | --- |
| **Question** | Version, key, extensions, subject, lifetime, appearance in XML/QR |
| **Claim A** | Illustrative profile: X.509 **v3**, RFC 5280; Signature “SHA256 with ECDSA”; SubjectPublicKeyInfo key length P-256; NotAfter = generation + up to 60 months; Key Usage digitalSignature + keyEncipherment **critical**; EKU clientAuth; SKI/AKI SHA-1 of subjectPublicKey BIT STRING; CSR subject RDNs in Table 1. Full chain in `ds:X509Certificate`. Profile is illustrative pending CP/CPS. |
| **Source A** | SEC-1.2 §2.2.2 pp.13–17; §2.3.3 items 1.5.x p.21; §2.2.1 req. 17 p.13. |
| **Claim B** | XML contains base64 X.509 in KeyInfo. QR Tag 8 XPath says X509Certificate; QR Tag 8 hex is SPKI; Resolution says public key. |
| **Source B** | GL pp.56, 61–62; RES ID 8. |
| **Conflict?** | Curve (3.1). Whether the certificate itself appears in QR Tag 8 (3.7). Final CP/CPS unpublished. |
| **Precedence analysis** | Illustrative profile may be implemented as a *parser* of whatever ZATCA returns, not as a local certificate factory. |
| **Final conclusion** | **RESOLVED — TEST ONLY / parser.** Do not invent certificate fields. Do not issue local CSIDs. XML: certificate **does** appear (KeyInfo). QR: whether the certificate appears is **UNRESOLVED** (Tag 8). Fingerprint: SEC-1.2 requires SigningCertificateV2 digest of certs; Guideline hashes the certificate with SHA-256 then Base64 of the *hex string* (p.53) — another educational vs ETSI mismatch. |
| **Confidence** | Medium on illustrative fields. High that HASEM must not mint CSIDs. |
| **Implementation consequence** | Certificate metadata parser / registry may store a ZATCA-issued cert. No local CA. |

---

### 3.11 XML signature placement

| Field | Content |
| --- | --- |
| **Question** | Exact XML location, namespaces, IDs, transforms |
| **Claim A** | UBL `cac:Signature` IDs: `urn:oasis:names:specification:ubl:signature:1`; referenced/signature ID `urn:oasis:names:specification:ubl:signature:Invoice`; method `urn:oasis:names:specification:ubl:dsig:enveloped:xades`. Stamp mandatory for simplified (KSA-2 positions 1–2 = 02). |
| **Source A** | XML-1.2 BR-KSA-28/29/30 p.55; BR-KSA-60 pp.57–58. |
| **Claim B** | Nested path `Invoice/ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent/sig:UBLDocumentSignatures/sac:SignatureInformation/ds:Signature` with SignedInfo, SignatureValue, KeyInfo, Object/QualifyingProperties/SignedProperties. |
| **Source B** | GL §5 XPaths pp.54–56, 61. SEC-1.2 §2.3.3 describes ds:* and xades:* without the UBL wrapper. |
| **Conflict?** | No conflict on the existence of both `cac:Signature` (UBL signature reference) and `ext:UBLExtensions` (actual ds:Signature). Exact namespace URI strings for `sig:` / `sac:` / `xades:` are not printed in SEC-1.2 (ETSI uses `http://uri.etsi.org/01903/v1.3.2#`; SignedProperties Type in SEC-1.2 is `http://uri.etsi.org/01903#SignedProperties`). |
| **Precedence analysis** | BR-KSA IDs are normative. UBL extension nesting follows UBL 2.1 signature extension practice as shown in the Guideline. Fine-grained ID attributes on `ds:Signature` / `xades:SignedProperties` are not fully specified by ZATCA beyond the Type URI and the two References. |
| **Final conclusion** | **RESOLVED enough for a future blueprint; BLOCKED for production emission** (depends on CSID + signing input). Blueprint in §8. |
| **Confidence** | High on BR-KSA IDs and exclusions. Medium on every attribute. |
| **Implementation consequence** | Do not emit signature XML now. |

---

### 3.12 Hash/signature circularity

| Field | Content |
| --- | --- |
| **Question** | How ZATCA avoids hash(XML) changing when the signature/QR are inserted |
| **Claim A** | Invoice hash and first XAdES Reference exclude `ext:UBLExtensions`, `cac:Signature`, and the QR `AdditionalDocumentReference`. Signature lives in the excluded extension. QR lives in the excluded QR element. Inserting them does not change the invoice hash. SignedInfo is signed, not re-hashed into the invoice hash. |
| **Source A** | XML-1.2 BR-KSA-26; SEC-1.2 §2.3.3 Transforms; SEC-1.2 req. 12 (XML except QR). |
| **Claim B** | None that reintroduces a loop, provided those exclusions are applied. |
| **Source B** | — |
| **Conflict?** | No, for the invoice hash. A second-order issue remains: if ECDSA signs SignedInfo, SignedInfo must be complete *before* SignatureValue exists (standard XMLDSig). If ECDSA signs the invoice hash (Guideline), SignatureValue does not depend on SignedInfo canonical bytes. That is the §3.2 conflict, not a circularity. |
| **Final conclusion** | **RESOLVED** (dependency graph in §5). |
| **Confidence** | High |
| **Implementation consequence** | Future implementation must keep the same exclusions as Phase 7. |

---

### 3.13 QR relationship to XML signature

| Field | Content |
| --- | --- |
| **Question** | Tag 7/8/9 vs XML SignatureValue / certificate / CA |
| **Claim A** | Tag 7 XPath = SignatureValue; Tag 8 XPath = X509Certificate; Tag 9 from PCSID (CA). |
| **Source A** | GL pp.61–63. |
| **Claim B** | Tag 7 = signature of XML hash; Tag 8 = public key extracted from private key; Tag 9 = CA signature of the stamp / public key. Tag 8 hex is SPKI, not the certificate. |
| **Source B** | SEC-1.2 Table 3; GL p.62 hex. |
| **Conflict?** | Yes for Tag 7 identity with SignatureValue encoding and Tag 8 certificate vs key. Tag 9 is independent of the invoice ECDSA (different signer: ZATCA CA). |
| **Final conclusion** | Relationship **partially resolved**: Tag 6 should match the invoice hash / first DigestValue (encoding still conflicted, §3.14). Tag 7 is *some* representation of the EGS invoice signature. Tag 8 is *some* representation of the EGS public key (not clearly the cert). Tag 9 is the CA signature of that public key, not the invoice signature. Exact bytes UNRESOLVED for 7–9. |
| **Confidence** | High on the logical split. Low on bytes. |
| **Implementation consequence** | Do not wire production Tags 7–9. |

---

### 3.14 QR Tag 6 encoding (discovered conflict; Phase 8 already shipped)

| Field | Content |
| --- | --- |
| **Question** | Is Tag 6 32 raw hash bytes or the 44-character Base64 digest string as UTF-8? |
| **Claim A** | “[for tag 6] Length: length of hash (SHA256) is 32 bytes. Value: the byte array constituting the value of the field.” Same text in v1.1 and v1.2. |
| **Source A** | SEC-1.2 §4.1 p.25; SEC-1.1 §4.1 p.25. |
| **Claim B** | Guideline example Tag 6 length **44**, value ASCII Base64 of the 32-byte SHA-256 (`QnVEexW4nWv4…`). XPath = `ds:Reference/ds:DigestValue`. Same document’s “order of operations” also says every TLV value is UTF-8 (which would make 44-char Base64 consistent, and 32 raw bytes inconsistent). |
| **Source B** | GL §6.2 p.60; GL p.61; SEC-1.2 §4.1 order of operations step 2 p.25 (internal tension with the 32-byte bullet). |
| **Conflict?** | **YES**, including an internal tension inside Security Features. |
| **Precedence analysis** | XML-1.2 BR-KSA-27 defers QR details to Security Features. Security Features simultaneously states 32 bytes and “encode Value in UTF-8.” Guideline example matches Phase 7’s stored Base64 `invoice_hash` string (44 chars) and DigestValue. **Do not “fix” Phase 8 in 9A.** |
| **Final conclusion** | **UNRESOLVED.** Phase 8 remains as accepted (UTF-8 of Base64 invoice_hash). |
| **Confidence** | High that a conflict exists. |
| **Implementation consequence** | Do not change Phase 8. Record as Conflict C-TAG6. |

---

### 3.15 Cryptographic retry semantics

| Field | Content |
| --- | --- |
| **Question** | Same invoice retried: ICV, PIH, hash, signature, certificate |
| **Claim A** | Clearance: rejected submissions are repeated as a new submission; accepted documents are stored. Seller may optionally include its stamp; ZATCA may add another stamp and update QR for standard invoices. |
| **Source A** | GL §4.3.2 pp.50–51. |
| **Claim B** | No official statement that ECDSA must be deterministic. No official statement that a transport retry must reuse the same SignatureValue. Tampering / altering generated invoices is prohibited (RES prohibited functionalities). |
| **Source B** | RES p.15; SEC-1.2 (no RFC 6979). |
| **Conflict?** | No direct conflict; a gap. |
| **Precedence analysis** | Separate three cases (below). Do not modify Phase 7. |
| **Final conclusion** | **RESOLVED as a gap-with-rules:** see §7. Deterministic ECDSA is **not required** by official documents. |
| **Confidence** | High that RFC 6979 is absent. Medium on retry reuse (architecture, not regulation). |
| **Implementation consequence** | Persist a produced stamp for *transport* retry so the legal XML does not churn. New legal invoice → new ICV/PIH/hash (Phase 7). Do not invent deterministic ECDSA “for convenience.” |

---

### 3.16 Production key custody

| Field | Content |
| --- | --- |
| **Question** | Official expectation for private-key storage |
| **Claim A** | FIPS 186 keygen; keys **non-exportable**; HSM **or** software module; disk encryption if software; protect activation data; revoke if stolen/compromised; export of stamping keys is a **prohibited functionality**. |
| **Source A** | SEC-1.2 §2.2.1 req. 6, 8, 9 pp.11–12; RES pp.12, 15. |
| **Claim B** | — |
| **Source B** | — |
| **Conflict?** | No |
| **Final conclusion** | **RESOLVED as architecture requirements** (not implemented here). |
| **Confidence** | High |
| **Implementation consequence** | Future production signer must keep private keys out of application tables (Phase 9 already does not store private keys). Support HSM or encrypted software module. No key export API. |

---

## 4. Exact resolved pipeline (byte / data flow)

Resolved pieces use solid arrows. Unresolved cryptographic choices use `??`.

```text
A  = UBL invoice XML
     minus ext:UBLExtensions
     minus cac:Signature
     minus cac:AdditionalDocumentReference[cbc:ID='QR']

B  = C14N11(A)
C  = SHA-256(B)                         # 32 bytes  [RESOLVED]
D  = Base64(C)                          # invoice_hash / PIH / first DigestValue string  [RESOLVED as XML digest]
     Tag 6 TLV value = C (32 bytes)  ??  OR  UTF-8(D) (typically 44 bytes)  [UNRESOLVED]

E  = xades:SignedProperties
     (SigningTime; SigningCertificateV2 per SEC-1.2;
      Guideline instead uses SigningCertificate)     [profile named; details conflict]
F  = SHA-256(C14N(E)) then Base64       # second ds:Reference DigestValue
     (Guideline instead: linearize and strip spaces — not ETSI C14N)

G  = canonical ds:SignedInfo
     (CanonicalizationMethod, SignatureMethod URI??, Reference(C), Reference(F))

H  = ECDSA_sign(private_key, G)         # XMLDSig/XAdES  [UNRESOLVED vs I]
 I  = ECDSA_sign(private_key, C)         # Guideline Step 2  [UNRESOLVED vs H]
     curve??  encoding DER?? vs P1363??

J  = Base64(H or I)                     # ds:SignatureValue  [RFC2045 wrapping is required]
K  = ds:KeyInfo/ds:X509Certificate[]    # full chain, Production CSID  [EXTERNAL]

L  = QR TLV:
     Tags 1–5 UTF-8 text
     Tag 6  ?? (see D)
     Tag 7  ?? (J as UTF-8  OR  raw P1363  OR  raw DER)
     Tag 8  ?? (SPKI  OR  XY  OR  compressed  OR  X.509)
     Tag 9  EXTERNAL from PCSID  ?? encoding
M  = Base64(L)                          # QR payload stored as KSA-14  [RESOLVED wrapping]

N  = insert ds:Signature into ext:UBLExtensions (excluded from A)
O  = insert M into QR AdditionalDocumentReference (excluded from A)
P  = cac:Signature UBL identifiers (BR-KSA-28/29/30)

Invoice hash does not depend on N or O.
Next invoice PIH = D of this invoice.
```

**EGS production curve = UNRESOLVED**

---

## 5. Hash vs signature distinction

`invoice_hash` (Phase 7) is **not** the cryptographic stamp.

| Artifact | What it is | Bytes | Where it lives |
| --- | --- | --- | --- |
| **invoice_hash** | SHA-256 of C14N11(invoice with UBLExtensions, cac:Signature, and QR removed), then Base64 for XML storage | 32-byte digest; Base64 string in XML / PIH | KSA-13 PIH of the *next* invoice; first `ds:DigestValue`; QR Tag 6 (encoding unresolved) |
| **XML signature / cryptographic stamp** | XAdES-B-B enveloped signature over the invoice *as specified by XMLDSig SignedInfo*, **or** (Guideline) ECDSA of the invoice hash placed into `ds:SignatureValue` | ECDSA output, Base64 in `ds:SignatureValue` | `ext:UBLExtensions` + `cac:Signature`; QR Tag 7 (encoding unresolved) |
| **CSID / certificate** | ZATCA-issued X.509 identifying the EGS | X.509 DER, Base64 in KeyInfo | XML KeyInfo; **not clearly** QR Tag 8 |
| **Tag 9** | ZATCA Technical CA signature over the EGS public key | EXTERNAL | QR only, simplified + associated notes |

**Relationship:** the invoice hash is an *input/reference inside* the XAdES structure (first SignedInfo Reference). It is not automatically the ECDSA message. Collapsing “sign(invoice hash)” with “XMLDSig signs SignedInfo” is the Phase 9 error this document refuses to repeat.

### Circularity graph (required deliverable)

```text
Artifact A: invoice body XML after the three exclusions (no extensions, no UBL signature, no QR)
Artifact B: SHA-256(C14N11(A)) = invoice_hash = first ds:Reference DigestValue
Artifact C: xades:SignedProperties (cert digest, SigningTime, policy)
Artifact D: SHA-256 of canonical SignedProperties = second ds:Reference DigestValue
Artifact E: canonical ds:SignedInfo (includes B and D)
Artifact F: ECDSA(E)   XOR   ECDSA(B)     [UNRESOLVED which]
Artifact G: ds:SignatureValue = Base64(F)
Artifact H: QR TLV using B (Tag 6 encoding??), G (Tag 7 encoding??), public key (Tag 8??), Tag 9 (EXTERNAL)
Artifact I: serialized invoice = A + UBLExtensions(G, cert) + QR(H) + cac:Signature IDs

Dependency:
A → B
C → D
B + D → E
E → F → G          (if XMLDSig)
B → F → G          (if Guideline Step 2)
B + G + key + Tag9 → H
A + G + H → I

Non-dependency (why there is no loop):
I ↛ A  (extensions, signature, QR are excluded from A)
G ↛ B  (SignatureValue is not inside A)
H ↛ B  (QR is not inside A)
```

---

## 6. QR representation (Tags 7–9)

### Tag 7

```text
Tag 7 exact value source        = UNRESOLVED
Tag 7 exact byte representation = UNRESOLVED
Tag 7 exact serialization       = UNRESOLVED
```

Official observations (not a choice):

- SEC-1.2 Table 3 p.26: “ECDSA signature of the XML Hash.”
- GL p.61: XML source `ds:SignatureValue`.
- GL p.62 combined hex: tag `07`, length `96` (`0x60`), value = ASCII `MEUCIQD5zxyXOB7NvWf62rVEZAYU71jpy9HEEnZ0q9O96wrL6QIgQJzCGHbw6YBHLYVdO1wnUhBgKm8jMTyvck9M+rP9xYY=`.
- Independent decode of that ASCII: Base64 → 71-byte DER `SEQUENCE` starting `30 45 02 21 00 f9 cf 1c …` (not 64-byte P1363).
- PORT p.62: IEEE P1363 `r \|\| s` = 64 bytes; sample `extractR` does **not** implement P1363.

TLV wrapping of the whole QR: Tag (1 byte) + Length (1 byte) + Value, then Base64 of the concatenation (SEC-1.2 §4.1 steps 3–4). Tag 7 is **not** Base64-wrapped a second time at the QR layer.

### Tag 8

Official observations (not a choice):

- RES ID 8: EGS public key (simplified); optional ZATCA platform key (standard).
- SEC-1.2: public key extracted from the signing private key.
- GL XPath: `ds:X509Certificate` (certificate).
- GL p.62 combined hex: tag `08`, length `88` (`0x58`), value raw SPKI:

```text
30 56 30 10 06 07 2a 86 48 ce 3d 02 01 06 05 2b 81 04 00 0a 03 42 00 04
+ 32-byte X + 32-byte Y
```

Independent decode:

- `1.2.840.10045.2.1` id-ecPublicKey present
- `1.3.132.0.10` secp256k1 present
- `1.2.840.10045.3.1.7` NIST P-256 **absent**
- uncompressed point (`04`)
- length 88 = SPKI, not 64-byte raw XY, not X.509
- TLV value is **binary**, not Base64 text (unlike Tag 7 in the same dump)

PORT p.61: 64-byte BLOB (72 with magic). PORT p.82: compressed form.

**Production Tag 8 encoding = UNRESOLVED.**

### Tag 9

```text
Meaning              = ZATCA Technical CA ECDSA signature over the EGS cryptographic-stamp public key
When required        = Simplified tax invoices AND associated credit/debit notes
Who signs            = ZATCA technical CA (not the EGS)
Local generation     = FORBIDDEN
Type                 = EXTERNAL ZATCA ARTIFACT (from Production CSID)
Encoding             = UNRESOLVED
```

Independent parse of GL p.62 remainder after Tag 8: `09 48` + 72-byte DER `30 46 02 21 …` (TLV Tag 9, length 72, raw DER). GL pp.62–63 prose that says to copy “Signature Algorithm: ecdsa-with-SHA256” **contradicts that hex** and would store an algorithm name, not a signature.

---

## 7. Retry, determinism, custody

### Three cases (do not mix)

| Case | ICV | PIH | invoice_hash | signature | certificate |
| --- | --- | --- | --- | --- | --- |
| **Transport retry** (same legal XML resent after network failure) | same | same | same | **should be reused if already produced** (otherwise a non-deterministic ECDSA would change SignatureValue/QR without a new legal invoice). Not an explicit ZATCA rule; required to keep the persisted security record stable. | same CSID |
| **New legal invoice** | next | previous hash | new | new | same CSID until renewal |
| **Rejected then corrected / reissued** | treat as new legal invoice if XML content changes (Phase 7). Clearance GL §4.3.2: resubmit; warnings may be accepted; errors reject. | new if body changed | new if body changed | new if body changed | same unless CSID renewed |

Standard invoices: ZATCA platform may add/replace stamp and QR on clearance (GL p.51). Simplified: EGS stamps; Tag 9 still EXTERNAL.

**Deterministic ECDSA (RFC 6979):** not required by SEC-1.2, XML-1.2, RES, GL, or PORT. Do **not** introduce custom deterministic ECDSA merely for convenience. Persist the stamp.

**Custody (future architecture must support):** FIPS 186 generation; non-exportable keys; HSM or software module; disk encryption for software; protect activation data; revocation path; no export API (RES prohibited functionality).

---

## 8. Future XAdES implementation checklist (not to implement now)

ZATCA-specific (from SEC-1.2 §2.3.3 and XML-1.2 BR-KSA-28/29/30/60):

1. Enveloped XAdES, level B-B, ETSI EN 319 132-1.
2. Cover whole XML except QR (and, for the hash/Reference, also except UBLExtensions and cac:Signature — otherwise circular).
3. `ds:SignedInfo` with ≥2 `ds:Reference`.
4. First Reference: DigestMethod SHA-256; Transforms: three XPath exclusions then `http://www.w3.org/2006/12/xml-c14n11`; DigestValue = invoice_hash Base64.
5. Second Reference: Type `http://uri.etsi.org/01903#SignedProperties`; URI → SignedProperties; SHA-256 digest.
6. `ds:SignatureValue` Base64 RFC2045. **SignatureMethod URI and ECDSA encoding UNRESOLVED.**
7. `ds:KeyInfo/ds:X509Data/ds:X509Certificate` ≥1, **full chain** to trust anchor.
8. SignedSignatureProperties: SigningTime; **SigningCertificateV2** (SEC) vs SigningCertificate (GL) — prefer SEC when implementing, but validator behavior UNRESOLVED.
9. SignaturePolicyIdentifier: “explicit identifier of a signature policy (this document)” — SEC-1.2 itself.
10. DataObjectFormat MimeType always `text/xml`; ObjectReference → the invoice Reference.
11. UBL `cac:Signature` IDs/method URNs per BR-KSA-28/29/30.
12. Nesting (from Guideline XPaths; UBL 2.1 signature extension):

```text
Invoice
  ext:UBLExtensions
    ext:UBLExtension
      ext:ExtensionContent
        sig:UBLDocumentSignatures
          sac:SignatureInformation
            ds:Signature
              ds:SignedInfo
                ds:CanonicalizationMethod
                ds:SignatureMethod          # URI UNRESOLVED
                ds:Reference                # invoice, with XPath+C14N11 transforms
                ds:Reference                # SignedProperties
              ds:SignatureValue
              ds:KeyInfo
                ds:X509Data
                  ds:X509Certificate+       # CSID chain
              ds:Object
                xades:QualifyingProperties
                  xades:SignedProperties
                    xades:SignedSignatureProperties
                      xades:SigningTime
                      xades:SigningCertificateV2
                      xades:SignaturePolicyIdentifier
                    xades:SignedDataObjectProperties
                      xades:DataObjectFormat  # text/xml
  cac:Signature                             # BR-KSA-28/29/30
  cac:AdditionalDocumentReference[ID=QR]    # KSA-14, excluded from hash
```

Expected namespaces (from incorporated UBL/ETSI/XMLDSig; **not all printed by ZATCA**):

- `ds`: `http://www.w3.org/2000/09/xmldsig#`
- `xades`: `http://uri.etsi.org/01903/v1.3.2#` (typical ETSI EN 319 132-1)
- `ext`: `urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2`
- `sig` / `sac`: UBL Common Signature Components (Guideline uses prefixes; URNs not reprinted in SEC-1.2)

Do not implement until Conflicts C-CURVE, C-SIGNINPUT, C-SIGENC are resolved **or** a ZATCA-issued CSID + validator verdict provides empirical precedence (that empirical step is outside 9A).

---

## 9. Official example reconstruction (independent; not HASEM)

### 9.1 QRCodeCreation.pdf Tags 1–5 (p.15)

Official Base64:

`AQxCb2JzIFJlY29yZHMCDzMxMDEyMjM5MzUwMDAwMwMUMjAyMi0wNC0yNVQxNTozMDowMFoEBzEwMDAuMDAFBjE1MC4wMA==`

Independent TLV parse (70 bytes):

| Tag | Len | UTF-8 value |
| --- | --- | --- |
| 1 | 12 | `Bobs Records` |
| 2 | 15 | `310122393500003` |
| 3 | 20 | `2022-04-25T15:30:00Z` |
| 4 | 7 | `1000.00` |
| 5 | 6 | `150.00` |

Matches the document’s stated fields. This example does **not** include Tags 6–9.

### 9.2 Guideline §6.2 / p.62 combined hex (Tags 1–9)

Independent parse of the concatenated hex on GL page 62:

| Tag | Len | Structure |
| --- | --- | --- |
| 1 | 23 | UTF-8 `Ahmed Mohamed AL Ahmady` |
| 2 | 15 | UTF-8 `301121971500003` |
| 3 | 20 | UTF-8 `2022-03-13T14:40:40Z` |
| 4 | 7 | UTF-8 `1108.90` |
| 5 | 5 | UTF-8 `144.9` |
| 6 | 44 | UTF-8 Base64 `QnVEexW4nWv4CaE39a/66Jp/OXO/evHQ8pDlG7weq/4=` → decodes to **32** hash bytes |
| 7 | 96 | UTF-8 Base64 `MEUCIQD5zxyX…xYY=` → decodes to **71-byte DER ECDSA** `30 45 …` |
| 8 | 88 | **Binary SPKI** secp256k1 uncompressed (see §6) |
| 9 | 72 | **Binary DER ECDSA** `30 46 02 21 …` (no inner Base64) |

Notes:

- The per-field hex table on GL p.60 corrupts Tag 8 with U+FFFD (`ef bf bd`) and lists impossible lengths (Tag 7 “192”, Tag 8 “48”, Tag 9 “144”). The **combined** dump on p.62 is the usable example.
- Tag 6 length 44 contradicts SEC-1.2 “32 bytes.”
- Tag 7 is textual Base64; Tags 8–9 are raw binary in the same QR. That mixed encoding is what the example actually contains.
- Tag 8 OID is secp256k1, contradicting SEC-1.2 “P-256.”

### 9.3 Guideline Step 2 SignatureValue example (p.53)

`MEQCIGvLa-…HMNLw==` (truncated in PDF wrapping) is a Base64 string with `MEQ` prefix, i.e. typical DER ECDSA `SEQUENCE` (not 64-byte P1363). Consistent with Tag 7’s `MEU` example, inconsistent with Portal P1363 prose.

### 9.4 Portal `extractR` (p.62)

The method Base64-decodes the signature string, **SHA-256 hashes those bytes**, then returns `hash[0..32)`. That is not `r` from P1363 and not `r` from DER. The sample cannot be used as a specification of Tag 7.

---

## 10. Unresolved official conflicts

For every item: **NO PRODUCTION IMPLEMENTATION SHOULD BE BASED ON THIS FIELD YET.**

### C-CURVE — elliptic curve

- **Topic:** NIST P-256 vs secp256k1
- **Source A:** SEC-1.2 §2.2.2 p.14 “P-256”; req. 6 FIPS 186
- **Source B:** GL p.57 `secp256k1`; PORT p.8 and p.82; GL p.62 SPKI OID 1.3.132.0.10
- **Exact conflict:** Two different curves. Portal equates them incorrectly.
- **Why it matters:** Wrong curve → non-verifiable signatures and rejected CSIDs.
- **Can precedence be established?** No. Normative profile is illustrative and OID-less; operational examples are secp256k1.
- **Decision:** UNRESOLVED. `EGS production curve = UNRESOLVED`

### C-SIGNINPUT — ECDSA message

- **Topic:** sign(invoice hash) vs sign(canonical SignedInfo)
- **Source A:** SEC-1.2 §2.3.3 SignedInfo
- **Source B:** GL §5 Step 2
- **Exact conflict:** Different signed bytes.
- **Why it matters:** A “correct” XAdES SignatureValue will fail a validator that checks ECDSA(invoice_hash), and vice versa.
- **Can precedence be established?** Not from paper without validator evidence. SEC-1.2 is the named standard; GL is what developers are shown.
- **Decision:** UNRESOLVED

### C-SIGENC — signature encoding

- **Topic:** DER ASN.1 ECDSA vs IEEE P1363 `r \|\| s`; SignatureMethod URI
- **Source A:** GL examples DER-in-Base64; SEC-1.2 only says Base64 RFC2045
- **Source B:** PORT p.62 P1363 64 bytes (sample code does not implement it)
- **Exact conflict:** 71-ish DER vs 64-byte P1363; URI unspecified
- **Why it matters:** Tag 7 and SignatureValue verification
- **Can precedence be established?** No
- **Decision:** UNRESOLVED

### C-TAG7 — QR Tag 7 serialization

- **Topic:** UTF-8(Base64(DER)) vs raw P1363 vs identity with SignatureValue
- **Source A:** GL p.61–62
- **Source B:** PORT p.62; SEC-1.2 “signature of the XML Hash”
- **Exact conflict:** as §3.6
- **Why it matters:** QR validation / ZATCA app
- **Can precedence be established?** No
- **Decision:** UNRESOLVED

### C-TAG8 — QR Tag 8 serialization

- **Topic:** SPKI vs XY vs compressed vs X.509
- **Source A:** GL p.62 SPKI 88 bytes; SEC-1.2 “public key extracted from private key”
- **Source B:** GL p.61 X509Certificate XPath; PORT 64-byte BLOB / compressed
- **Exact conflict:** as §3.7, including intra-Guideline XPath vs hex
- **Why it matters:** QR validation
- **Can precedence be established?** No
- **Decision:** UNRESOLVED

### C-TAG9ENC — Tag 9 byte encoding

- **Topic:** How to extract Tag 9 from PCSID
- **Source A:** GL p.62 hex = 72-byte DER TLV
- **Source B:** GL p.63 “copy Signature Algorithm: ecdsa-with-SHA256”; p.61 XPath X509Certificate
- **Exact conflict:** Intra-Guideline
- **Why it matters:** Simplified invoice QR
- **Can precedence be established?** Meaning is EXTERNAL; encoding is not
- **Decision:** Meaning EXTERNAL (resolved); encoding UNRESOLVED

### C-TAG6 — QR Tag 6 32 vs 44

- **Topic:** raw SHA-256 vs Base64 UTF-8
- **Source A:** SEC-1.2 / SEC-1.1 §4.1 “32 bytes”
- **Source B:** GL §6.2 length 44; SEC-1.2 order-of-operations UTF-8
- **Exact conflict:** 32 binary vs 44 ASCII
- **Why it matters:** Phase 8 already emits 44-char Base64 UTF-8
- **Can precedence be established?** No without changing accepted Phase 8 or getting a ZATCA clarification
- **Decision:** UNRESOLVED. **Do not change Phase 8 in this phase.**

### C-CERTDIGEST — certificate digest in SignedProperties

- **Topic:** ETSI SigningCertificateV2 digest of DER certificate vs Guideline SHA-256 hex then Base64 of hex
- **Source A:** SEC-1.2 §2.3.3 SigningCertificateV2 p.22
- **Source B:** GL §5 Step 3 p.53
- **Exact conflict:** digest-of-cert vs Base64(hex(SHA-256(cert)))
- **Why it matters:** XAdES verification
- **Can precedence be established?** SEC-1.2 names V2; still educational vs ETSI
- **Decision:** UNRESOLVED for production emission

### C-XMLDSIG-URI — SignatureMethod / CanonicalizationMethod URIs

- **Topic:** Exact algorithm URIs
- **Source A:** SEC-1.2 requires XMLDSig algorithms; Transform C14N11 URI **is** given: `http://www.w3.org/2006/12/xml-c14n11`
- **Source B:** none names `ecdsa-sha256` URI or SignedInfo CanonicalizationMethod URI
- **Exact conflict:** gap, not a two-way clash
- **Why it matters:** XAdES interoperability
- **Can precedence be established?** C14N11 transform URI yes; SignatureMethod no
- **Decision:** SignatureMethod URI UNRESOLVED; invoice-body C14N11 transform URI RESOLVED

---

## 11. Implementation readiness matrix

| Component | Status | Safe to implement? |
| --- | --- | --- |
| Certificate metadata parser | RESOLVED — TEST ONLY | Yes, as a parser of a ZATCA-issued cert. No local issuance. (Phase 9 already has `X509CertificateParser`.) |
| Certificate registry | RESOLVED — TEST ONLY | Yes, store metadata / fingerprint / PEM of an externally issued cert. No private keys. (Phase 9 `e_invoice_certificates`.) |
| Production private-key abstraction | RESOLVED — TEST ONLY | Interface + HSM/software-module requirement may be designed. Must not generate production EGS keys until C-CURVE is resolved. |
| ECDSA signer | RESOLVED — TEST ONLY | Test-only signer over Phase 7 digest is acceptable as a test profile. Production signer **BLOCKED** (C-CURVE, C-SIGNINPUT, C-SIGENC). |
| XAdES XML structure | BLOCKED | Named as XAdES-B-B enveloped, but signing input, URIs, cert digest, and CSID are unresolved/external. Keep `materialize()` throwing. |
| Tag 7 | UNRESOLVED | No production. |
| Tag 8 | UNRESOLVED | No production. |
| Tag 9 | EXTERNAL DEPENDENCY | Do not generate. Encoding UNRESOLVED. |
| CSID integration | EXTERNAL DEPENDENCY | Documented only. No CSR/OTP/FATOORA. |
| QR Tags 7–9 | UNRESOLVED | No production. Tags 1–6 unchanged (Tag 6 conflict recorded, not “fixed”). |
| Production stamp | BLOCKED | Production-critical ambiguities remain. |

---

## 12. HASEM impact (future Phase 9 production — do not do now)

When (and only when) C-CURVE, C-SIGNINPUT, C-SIGENC, C-TAG7, C-TAG8, and C-TAG9ENC are resolved from an authoritative ZATCA clarification **or** a later official revision:

1. Bind a production `CryptographicStampSigner` that uses a ZATCA Production CSID and a non-exportable private key (HSM or encrypted module). Keep `DeferredCryptographicStampSigner` until then.
2. Implement XAdES-B-B into `ext:UBLExtensions` per §8, without changing Phase 7 hash exclusions.
3. Persist `ds:SignatureValue` (and QR Tags 7–9) on the security record so transport retries reuse the same stamp.
4. Populate QR Tags 7–8 from the resolved encodings; copy Tag 9 from PCSID; never generate Tag 9.
5. Do **not** treat Phase 9 test secp256k1 + DER + sign(invoice_hash) as the production profile.
6. Do **not** modify Phase 7 ICV/PIH/hash.
7. Do **not** silently change Phase 8 Tag 6 without an explicit regulatory decision on C-TAG6.
8. Onboarding (CSR, OTP, Compliance CSID, Production CSID, FATOORA HTTP) is a separate phase after this specification is unblocked.

**This phase (9A) changes none of the above in code.**

---

## 13. Code integrity

Intended result of Phase 9A:

```text
Documentation/reconciliation changes only.
```

Allowed path: `docs/einvoicing/PHASE-9A-ZATCA-CRYPTOGRAPHIC-SPECIFICATION-RECONCILIATION.md`

Forbidden: `app/`, migrations, `config/`, routes, services, models, production signing, QR production behavior.

---

## 14. Final decision

Production-critical ambiguities remain (curve, signing input, signature encoding, Tags 7–8, Tag 9 encoding). Therefore this is **not**:

```text
READY FOR PRODUCTION CRYPTOGRAPHIC IMPLEMENTATION
```

Infrastructure that does not require those choices (certificate *parser*, registry without private keys, private-key *interface*, test-only ECDSA, deferred production signer) may exist and may stay. Production signing, XAdES emission, CSID onboarding, and QR Tags 7–9 remain blocked.

```text
READY FOR LIMITED LOCAL IMPLEMENTATION
```

with the explicit rider that **production cryptographic stamping is BLOCKED** until the Unresolved Official Conflicts are closed by ZATCA, not by guesswork.

---

## Appendix A — X.509 illustrative profile (do not invent extra fields)

From SEC-1.2 §2.2.2 pp.13–17 only:

| Field | Stated value |
| --- | --- |
| Version | 3 |
| SerialNumber | ≥64 bits entropy, duplicate-checked |
| Signature | SHA256 with ECDSA |
| Issuer | Subject DN of issuing CA |
| NotBefore | generation time |
| NotAfter | generation + up to 60 months |
| Subject | CSR Table 1 RDNs |
| SubjectPublicKeyInfo | “P-256” (OID UNRESOLVED) |
| CRL DP, AKI, SKI, Certificate Policies, AIA | as table; OIDs “to be defined by the CA” |
| Key Usage | digitalSignature, keyEncipherment, critical |
| EKU | clientAuth |
| CSR CN | solution unit name / asset tracking |
| EGS serial | `1-…\|2-…\|3-…` in SAN |
| organizationIdentifier 2.5.4.97 | 15-digit VAT, starts and ends with 3 |
| OU / O / C | as table |
| Invoice Type businessCategory | 4-digit TSCZ map |
| Location / Industry | SAN |

Final CP/CPS unpublished → treat as illustrative.

## Appendix B — What “P-256 (secp256k1)” is

| Name | SECG | NIST | OID |
| --- | --- | --- | --- |
| NIST P-256 | secp256**r1** | P-256 | 1.2.840.10045.3.1.7 |
| secp256k1 | secp256**k1** | (not a NIST P-curve) | 1.3.132.0.10 |

They are not the same curve. PORT p.82’s conjunction is an error, not a specification.

## Appendix C — Phase 9 code this document does not change

Existing Phase 9 foundation (context only):

- Production container binds `DeferredCryptographicStampSigner`.
- `TestCryptographicStampSigner` is test-only and signs the Phase 7 32-byte digest with secp256k1 ECDSA, storing Base64(DER) and raw SPKI.
- `XadesEnvelopedSignature::materialize()` throws.
- Tag 9 is never generated.
- No private keys in `e_invoice_certificates` / `e_invoice_cryptographic_stamps`.

Those choices remain **test/foundation**, not regulatory conclusions of 9A.
