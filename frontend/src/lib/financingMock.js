export const mockProducts = [
  { key: 'aba-small-business-loan', provider: 'ABA Bank', name: 'Small Business Loan', currency: ['USD', 'KHR'], amount_range: { USD: { min: 1000, max: 200000 }, KHR: { min: 4000000, max: 800000000 } }, term: 'Up to 10 years', purpose: ['Working capital', 'Operational needs'], requirements: ['Business operating in Cambodia for at least 12 months', 'Collateral information', 'Identity document'], source_url: 'https://www.ababank.com/en/small-business-loan/', last_verified: '2026-10-25', verification_status: 'official_source_reviewed', evaluation: [] },
  { key: 'acleda-merchant', provider: 'ACLEDA Bank', name: 'Merchant / small enterprise financing', currency: ['USD', 'KHR'], amount_range: null, term: null, purpose: ['Business working capital'], requirements: ['Account and payment history requirements need provider confirmation'], source_url: 'https://www.acledabank.com.kh/kh/eng/ps_credit?t=b', last_verified: '2026-10-25', verification_status: 'requirements_need_confirmation', evaluation: [] },
  { key: 'ftb-cgcc-sme', provider: 'FTB', name: 'CGCC-backed SME financing', currency: ['USD', 'KHR'], amount_range: null, term: null, purpose: ['SME financing'], requirements: ['CGCC and bank conditions need confirmation'], source_url: 'https://ftb.com.kh/products/sme/cgcc', last_verified: '2026-10-25', verification_status: 'source_requires_manual_confirmation', evaluation: [] },
];

export const documentLabels = {
  identity_document: 'Identity document',
  registration_tax_information: 'Registration / tax information',
  bank_statements: 'Bank statements',
  business_financial_records: 'Business financial records',
  existing_debt_information: 'Existing debt information',
};
