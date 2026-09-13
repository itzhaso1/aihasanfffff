<?xml version="1.0" encoding="UTF-8"?>
<schema xmlns="http://purl.oclc.org/dsdl/schematron">
    <pattern>
        <rule context="/*">
            <assert test="local-name() = 'Invoice' or local-name() = 'CreditNote'">
                Root element must be Invoice or CreditNote.
            </assert>
            <assert test="namespace-uri() = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2' or namespace-uri() = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2'">
                Root namespace must be the official UBL 2.1 Invoice or CreditNote namespace.
            </assert>
        </rule>
    </pattern>
</schema>
