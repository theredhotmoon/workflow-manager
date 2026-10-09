import DOMPurify from 'dompurify';
import { createProcedureSanitizer } from './procedureHtmlSanitizer.js';

/** Procedure HTML made safe for v-html (see procedureHtmlSanitizer.js). */
export const sanitizeProcedureHtml = createProcedureSanitizer(DOMPurify);
