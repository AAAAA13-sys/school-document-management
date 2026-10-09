# Philippine requirements research

Researched 7 October 2026. This project is institution-neutral. The following national sources inform proposed controls; institution-specific policies must be configured for the actual deployment. Government-record and civil-service rules depend on the institution's legal status. The original 45-story baseline is preserved.

## 2. Philippine standards relevant to this system

| Authority / official source | Verified relevance | Proposed DMS consequence |
|---|---|---|
| [RA 10173 — Data Privacy Act](https://privacy.gov.ph/data-privacy-act-/) | Education and health information are sensitive personal information. Processing needs an applicable lawful basis, legitimate purpose, proportionality and security; data-subject rights also apply. | Maintain processing-purpose/lawful-basis records with the DPO; restrict access, minimize integration payloads and support reviewed access/correction requests. Do not use blanket consent as the basis for every transaction. |
| [NPC Circular 2023-06 announcement](https://privacy.gov.ph/npc-issues-circulars-to-strengthen-personal-data-protection-in-ph/) | Covers security of personal data for government and private organizations; replaces Circular 16-01 and addresses organizational, physical and technical safeguards. | Include privacy impact assessment, access reviews, security training, incident handling and tested recovery in release work. Compliance includes operating procedures beyond application code. |
| [RA 9470 implementing rules](https://nationalarchives.gov.ph/wp-content/uploads/2024/09/IRR-of-R.A.-9470.pdf) and [NAP General Circular 5 / GRDS 2023](https://nationalarchives.gov.ph/wp-content/uploads/2024/02/NAP_General_Circular_No_5.pdf) | Government records require applicable approved retention schedules and disposal authority. | Records Officer must map each record series to an applicable schedule. Retention expiry alone cannot authorize deletion; require documented NAP authority where applicable, eligibility review and hold checks. No invented universal retention period. |
| [NAP electronic records primer](https://nationalarchives.gov.ph/wp-content/uploads/2025/04/ERMP-PRIMER25.pdf) | Electronic records management requires inventory and a managed transition. | Inventory paper and digital holdings; retain original location/custodian and migration provenance. Scanning does not authorize destruction of originals. |
| [RA 11909 — civil registry certificate validity](https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/2/95608) | Covered birth, marriage and death certificates have permanent validity, subject to the law's condition/authenticity and correction qualifications. | No arbitrary age-based expiry for a PSA birth certificate. A corrected certificate supersedes prior evidence through version history rather than silently overwriting it. |
| [CSC MC 05, s. 2026](https://www.csc.gov.ph/downloads/category/566-mc-no-05-2026) | Official CSC listing adopts CS Form 212, Revised 2026, the Personal Data Sheet. | Store form edition and submission date. HR approves which version and attachments apply to each employment/appointment transaction. The listing alone does not establish every transition rule. |
| [BIR Form 2316](https://bir-cdn.bir.gov.ph/local/pdf/2316%20Sep%202021%20ENCS_Final_corrected.pdf) and [RMC 34-2022](https://bir-cdn.bir.gov.ph/local/pdf/RMC%20No.%2034-2022%20%281%29.pdf) | Compensation tax certificates are employee/year records with furnishing obligations, including the January 31 rule and termination circumstances. | Payroll generates and owns tax calculations; DMS stores exact versions, tax year, employee mapping, acknowledgment/delivery evidence and controlled access. Do not expose payroll documents through student connectors. |
| [CHED eCAV requirements](https://ecav.ched.gov.ph/requirements) | Authentication workflows use certified academic credentials; requirements vary with graduate/undergraduate circumstances. | Support an authorized Registrar evidence package, certification metadata and export. Reconfirm current eCAV requirements for the actual transaction; its PDF submission rule is not a universal DMS upload rule. |
| [CHED CMO 14, s. 2019](https://legacy.ched.gov.ph/wp-content/uploads/CMO-No-14-Series-of-2019-Policies-and-Guidelines-in-the-Issuance-of-Certificate-of-Compliance-COPC-to-State-Universities-and-Colleges-and-Local-Universities-and-Colleges.pdf) | Its published preamble references the application of MORPHE provisions to SUCs/LUCs through CMO 30, s. 2009. | Have Registrar identify applicable CHED and program provisions. Do not dismiss a provision solely because the original manual is titled for private institutions, or assume all its provisions apply unchanged. |
| [RA 8792 — Electronic Commerce Act](https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/2/3888) | Recognizes electronic documents subject to applicable reliability, integrity and retention conditions. | Preserve provenance, checksums, versions and retrieval. Electronic recognition is not proof that an ordinary uploaded scan replaces institution-required originals. |
| [NPC opinion 2025-004](https://privacy.gov.ph/wp-content/uploads/2025/07/NPC-Advisory-Opinion-No.-2025-004-Philippine-Veterans-Affairs-Office_Redacted.pdf) | Discusses lawful data sharing and DSAs as recommended accountability measures rather than a universal mandatory instrument. | Identify controller/processor relationships for each integration. Internal modules, external processors and independent controllers need different governance; avoid a blanket “DSA required for every API” rule. |

Source availability varies: some government PDFs were accessible through indexed extracts but later direct fetches failed. No inaccessible full issuance is treated as having been comprehensively reviewed. Agency/internal requirements and later amendments must be checked when converting this research into operating policy.

## 3. Acceptance-criteria refinements within the existing 45 stories

These are proposed product controls inferred from the sources. Each is testable; none is claimed implemented by this documentation update.

| Existing stories | Proposed acceptance criteria |
|---|---|
| US-04, US-19, US-35 | A published requirement set records its academic year, stage, cohort, effective dates, policy source and approving officer. Editing a future set leaves existing applications on their recorded version. Optional/conditional documents do not block unrelated applicants. |
| US-08, US-19, US-36 | A document records whether it is an uploaded scan, certified copy or received original. Approval of a scan does not mark an original-only requirement complete. Original receipt records authorized receiver, date, location and a receipt reference. |
| US-19, US-24, US-36 | A previous-school credential request records request date, sender/recipient, applicable due date, receipt and verification. A reminder uses an approved configured deadline; an unresolved published deadline cannot become a default rule. |
| US-12, US-31, US-35, US-36 | Admission-to-student reuse requires confirmed identifier mapping. Name similarity alone cannot link records. Reuse retains the original version and source; enrollment completeness is evaluated against the enrollment requirement set. |
| US-04, US-20 | Categories have a documented validity policy. A covered civil registry certificate is not rejected merely for being old. Evidence concerns route to authorized review; corrected evidence creates a new version. |
| US-02, US-05, US-11, US-25 | Unauthorized users cannot infer restricted HR, health or payroll information through results, counts, previews, downloads or exports. Tests cover registrar, HR, payroll and integration actors separately. |
| US-08, US-37 | HR records include applicable form edition and employment transaction. An obsolete edition is flagged according to HR's configured effective-date policy; the system does not silently relabel it as the new edition. |
| US-12, US-38 | Form 2316 records are keyed to confirmed employee, employer, tax year and version. Replacement preserves prior versions and delivery history. Tax amounts remain authoritative in Payroll. |
| US-28, US-29, US-32 | Each connector has a purpose, owner and permitted fields/actions. A student connector cannot retrieve employee tax files; completeness events expose only the minimum necessary status and references. |
| US-08, US-12, US-39 | An access/correction request has verified requester authority, assigned owner and tracked outcome. Approved correction preserves the previous value/version and sends only authorized changes to the owning system. Identity data is not independently changed in all five systems. |
| US-40, US-41 | Every disposal candidate has record-series mapping, retention trigger and schedule reference. Missing mapping, active holds or missing required disposal authority blocks destruction. The disposal record includes approvals, authority reference, scope and execution evidence. |
| US-43, US-44 | Migration reconciles counts, identity links and checksums, with exceptions quarantined for review. An isolated restore verifies restricted access, document versions and custody metadata as well as file availability. |
| US-25, US-39 | A Registrar export package identifies its selected document versions and certification status. Export requires recipient/purpose authorization and is audited. Downloading a package does not by itself mark CHED authentication complete. |

Cross-cutting operational acceptance: DPO-reviewed processing inventory and PIA; documented incident escalation; approved backup/restore targets; named policy owners; and keyboard-accessible jQuery UI flows. These are release evidence, not additional user stories.

## 4. Five-system responsibility boundary

| System | Owns | DMS receives / returns |
|---|---|---|
| Online Admission | Applicant identity, eligibility rules, selection and offer | Applicant references and requirement context / evidence and completeness |
| Enrollment | Student identity, enrollment status, academic registration | Confirmed applicant/student mapping / verified enrollment evidence and outstanding originals |
| Employee Management | Employee identity, appointment and employment lifecycle | Employee references and HR policy context / scoped employee evidence |
| Payroll Management | Pay runs, compensation calculations and tax outputs | Employee/pay-run/tax-year context and generated files / receipts, versions and authorized delivery evidence |
| Document Management | Evidence, versions, verification, custody, access, audit and retention workflow | Shared document service for the other four systems |

No sixth business system is introduced. Institutional policy or CHED evidence can be a DMS category if later approved; a full accreditation module is outside this baseline.

## Policy decisions for the actual institution

Registrar must approve current admission/enrollment checklists, academic-year deadlines and original-document rules. HR and Payroll must approve applicable form versions and supporting records. The Records Officer and DPO must approve retention, lawful processing and integration access. No sample school policy is a default requirement.

## 6. Recommended build order

1. Expand the category/requirement model with effective dates, source references, applicant cohorts and original-receipt tracking (US-04/08/19).
2. Establish authoritative applicant/student/employee mappings and minimal connector contracts (US-28–38).
3. Add category validity policies, credential-request tracking and reviewed correction/access workflows.
4. Implement approved retention/holds/authorized disposal, then migration and restore evidence.
5. Connect real systems only after policy configurations and access boundaries pass acceptance testing.

Laravel, Bootstrap and jQuery UI remain suitable for these workflows. The framework choice does not itself establish compliance; the evidence model and operating controls are what this research changes.
