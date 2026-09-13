# UBL 2.1 XSD (official OASIS)

Source: OASIS Universal Business Language (UBL) 2.1 OS  
URL: https://docs.oasis-open.org/ubl/os-UBL-2.1/  
Release date: 4 November 2013  
License: OASIS Open  

ZATCA Electronic Invoice XML Implementation Standard (19 May 2023), chapter 10,
requires UBL Invoice 2.1 with target namespace
`urn:oasis:names:specification:ubl:schema:xsd:Invoice-2`
and common schemas from `http://docs.oasis-open.org/ubl/os-UBL-2.1/xsd/common/`.

Only the Invoice, CreditNote, and required common XSD files are stored here.
Signature/XAdES files are part of the official OASIS import graph and are not
used by HASEM Phase 6 XML generation.

Override the schema root with `E_INVOICE_UBL_XSD_PATH` when deploying a
different official UBL 2.1 layout.

ZATCA Schematron/SDK artifacts are not redistributed in this directory.
