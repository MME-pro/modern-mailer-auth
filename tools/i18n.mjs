/**
 * Translation tooling: extract, compile, and build the JS catalogue.
 *
 * Written here rather than leaning on WP-CLI because WP-CLI is not installed on
 * every machine this is developed on, and a translation pipeline that only one
 * person can run is a translation pipeline that rots. Everything below needs
 * nothing but Node.
 *
 *   node tools/i18n.mjs extract   -> languages/modern-mailer-oauth.pot
 *   node tools/i18n.mjs compile   -> .mo next to every .po
 *   node tools/i18n.mjs json      -> the JSON catalogue wp_set_script_translations reads
 *   node tools/i18n.mjs check     -> report untranslated strings
 */

import { readFileSync, writeFileSync, readdirSync, statSync, mkdirSync, existsSync } from 'node:fs';
import { join, relative, sep, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';

// fileURLToPath, not URL.pathname: this plugin lives under a directory with a
// space in its name, and pathname hands back the percent-encoded form - which
// silently finds nothing rather than failing.
const ROOT = dirname( fileURLToPath( new URL( '.', import.meta.url ) ) );
const DOMAIN = 'modern-mailer-oauth';
const LANGUAGES = join( ROOT, 'languages' );

/** Directories worth scanning, and the ones that are never shipped. */
const SCAN = [ 'includes', 'admin', 'src' ];
const SKIP = new Set( [ 'node_modules', 'build', 'vendor', '.git', 'tests', 'tools', 'broker', 'languages' ] );

const walk = ( dir, out = [] ) => {
	for ( const name of readdirSync( dir ) ) {
		if ( SKIP.has( name ) ) continue;
		const full = join( dir, name );
		if ( statSync( full ).isDirectory() ) walk( full, out );
		else if ( /\.(php|js|jsx)$/.test( name ) ) out.push( full );
	}
	return out;
};

/**
 * Pull translatable strings out of one file.
 *
 * Deliberately narrow: it understands the call shapes this codebase actually
 * uses and nothing else. A general gettext parser would be more code and more
 * ways to be subtly wrong about a string nobody wrote.
 */
const extractFrom = ( file ) => {
	const source = readFileSync( file, 'utf8' );
	const found = [];

	// __( 'text', 'domain' ) and its escaping variants, single or double quoted.
	const single =
		/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*(['"])((?:\\.|(?!\1)[^\\])*)\1\s*,\s*(['"])modern-mailer-oauth\3\s*\)/g;

	// _n( 'one', 'many', $count, 'domain' )
	const plural =
		/_n\(\s*(['"])((?:\\.|(?!\1)[^\\])*)\1\s*,\s*(['"])((?:\\.|(?!\3)[^\\])*)\3\s*,[^,]+,\s*(['"])modern-mailer-oauth\5\s*\)/g;

	let m;
	while ( ( m = single.exec( source ) ) ) {
		found.push( {
			msgid: literal( m[ 2 ], m[ 1 ] ),
			line: source.slice( 0, m.index ).split( '\n' ).length,
		} );
	}
	while ( ( m = plural.exec( source ) ) ) {
		found.push( {
			msgid: literal( m[ 2 ], m[ 1 ] ),
			plural: literal( m[ 4 ], m[ 3 ] ),
			line: source.slice( 0, m.index ).split( '\n' ).length,
		} );
	}

	return found;
};

/**
 * Turn the source text of a string literal into the string it denotes.
 *
 * The quote decides how much escaping happened, and getting this wrong is
 * silent: a double-quoted "line one\nline two" captured verbatim yields a
 * msgid containing a backslash and an n, which never matches the real string at
 * runtime, so that entry simply never translates and nothing reports it.
 *
 * PHP single quotes escape only \' and \\. Double quotes also do \n, \t, \r,
 * \", \$ and the rest; only the ones that appear in this codebase are handled,
 * because inventing support for \x41 that nothing uses is untested code.
 */
const literal = ( raw, quote ) => {
	if ( "'" === quote ) {
		return raw.replace( /\\(['\\])/g, '$1' );
	}

	return raw.replace( /\\([nrt"\\$])/g, ( _, c ) =>
		( { n: '\n', r: '\r', t: '\t' }[ c ] ?? c )
	);
};

/** PO escaping: the quote, the backslash, and the newline. Nothing else. */
const esc = ( s ) => s.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' ).replace( /\n/g, '\\n' );


const collect = () => {
	const strings = new Map();

	for ( const dir of SCAN ) {
		const full = join( ROOT, dir );
		if ( ! existsSync( full ) ) continue;

		for ( const file of walk( full ) ) {
			const where = relative( ROOT, file ).split( sep ).join( '/' );

			for ( const entry of extractFrom( file ) ) {
				const msgid = entry.msgid;
				const key = msgid + '\u0000' + ( entry.plural || '' );

				if ( ! strings.has( key ) ) {
					strings.set( key, {
						msgid,
						plural: entry.plural ?? null,
						refs: [],
					} );
				}

				strings.get( key ).refs.push( `${ where }:${ entry.line }` );
			}
		}
	}

	return strings;
};

const extract = () => {
	const strings = collect();
	mkdirSync( LANGUAGES, { recursive: true } );

	const out = [
		'# Copyright (C) Modern Mailer',
		'# This file is distributed under the same licence as the plugin.',
		'msgid ""',
		'msgstr ""',
		'"Project-Id-Version: Modern Mailer\\n"',
		'"MIME-Version: 1.0\\n"',
		'"Content-Type: text/plain; charset=UTF-8\\n"',
		'"Content-Transfer-Encoding: 8bit\\n"',
		'"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
		`"X-Domain: ${ DOMAIN }\\n"`,
		'',
	];

	for ( const { msgid, plural, refs } of strings.values() ) {
		out.push( `#: ${ refs.join( ' ' ) }` );
		out.push( `msgid "${ esc( msgid ) }"` );

		if ( plural ) {
			out.push( `msgid_plural "${ esc( plural ) }"` );
			out.push( 'msgstr[0] ""', 'msgstr[1] ""' );
		} else {
			out.push( 'msgstr ""' );
		}

		out.push( '' );
	}

	writeFileSync( join( LANGUAGES, `${ DOMAIN }.pot` ), out.join( '\n' ) );
	console.log( `extracted ${ strings.size } strings -> languages/${ DOMAIN }.pot` );
};

/** Parse a .po into { msgid: msgstr }, ignoring the header and empty entries. */
const parsePo = ( text ) => {
	const entries = [];
	let current = null;
	let field = null;

	const flush = () => {
		if ( current && current.msgid !== undefined ) entries.push( current );
		current = null;
	};

	for ( const raw of text.split( /\r?\n/ ) ) {
		const line = raw.trim();

		if ( '' === line || line.startsWith( '#' ) ) {
			if ( '' === line ) flush();
			continue;
		}

		let m;
		if ( ( m = line.match( /^msgid\s+"(.*)"$/ ) ) ) {
			flush();
			current = { msgid: m[ 1 ], msgstr: '', plurals: [] };
			field = 'msgid';
		} else if ( ( m = line.match( /^msgid_plural\s+"(.*)"$/ ) ) ) {
			current.msgid_plural = m[ 1 ];
			field = 'msgid_plural';
		} else if ( ( m = line.match( /^msgstr\[(\d+)\]\s+"(.*)"$/ ) ) ) {
			current.plurals[ Number( m[ 1 ] ) ] = m[ 2 ];
			field = 'plural' + m[ 1 ];
		} else if ( ( m = line.match( /^msgstr\s+"(.*)"$/ ) ) ) {
			current.msgstr = m[ 1 ];
			field = 'msgstr';
		} else if ( ( m = line.match( /^"(.*)"$/ ) ) && current ) {
			// A continuation line appends to whatever field is open.
			if ( 'msgid' === field ) current.msgid += m[ 1 ];
			else if ( 'msgid_plural' === field ) current.msgid_plural += m[ 1 ];
			else if ( 'msgstr' === field ) current.msgstr += m[ 1 ];
			else if ( field && field.startsWith( 'plural' ) ) {
				const i = Number( field.slice( 6 ) );
				current.plurals[ i ] += m[ 1 ];
			}
		}
	}

	flush();

	const unesc = ( s ) => s.replace( /\\n/g, '\n' ).replace( /\\"/g, '"' ).replace( /\\\\/g, '\\' );

	return entries
		.filter( ( e ) => '' !== e.msgid )
		.map( ( e ) => ( {
			msgid: unesc( e.msgid ),
			msgid_plural: e.msgid_plural ? unesc( e.msgid_plural ) : null,
			msgstr: unesc( e.msgstr ),
			plurals: e.plurals.map( unesc ),
		} ) );
};

/**
 * Write a .mo.
 *
 * The format is a header, two tables of (length, offset) pairs, and the strings
 * themselves. Plural forms are one entry whose original and translation are
 * NUL-joined, which is why they are assembled rather than written directly.
 */
const writeMo = ( entries, target ) => {
	const pairs = entries
		.filter( ( e ) => ( e.msgid_plural ? e.plurals.some( Boolean ) : '' !== e.msgstr ) )
		.map( ( e ) => ( {
			id: e.msgid_plural ? e.msgid + '\u0000' + e.msgid_plural : e.msgid,
			str: e.msgid_plural ? e.plurals.join( '\u0000' ) : e.msgstr,
		} ) )
		.sort( ( a, b ) => ( a.id < b.id ? -1 : a.id > b.id ? 1 : 0 ) );

	const ids = pairs.map( ( p ) => Buffer.from( p.id, 'utf8' ) );
	const strs = pairs.map( ( p ) => Buffer.from( p.str, 'utf8' ) );
	const n = pairs.length;

	const headerSize = 28;
	const tableSize = n * 8;
	let offset = headerSize + tableSize * 2;

	const idTable = Buffer.alloc( tableSize );
	const strTable = Buffer.alloc( tableSize );

	ids.forEach( ( b, i ) => {
		idTable.writeUInt32LE( b.length, i * 8 );
		idTable.writeUInt32LE( offset, i * 8 + 4 );
		offset += b.length + 1;
	} );

	strs.forEach( ( b, i ) => {
		strTable.writeUInt32LE( b.length, i * 8 );
		strTable.writeUInt32LE( offset, i * 8 + 4 );
		offset += b.length + 1;
	} );

	const header = Buffer.alloc( headerSize );
	header.writeUInt32LE( 0x950412de, 0 );          // magic
	header.writeUInt32LE( 0, 4 );                    // revision
	header.writeUInt32LE( n, 8 );                    // string count
	header.writeUInt32LE( headerSize, 12 );          // originals table
	header.writeUInt32LE( headerSize + tableSize, 16 ); // translations table
	header.writeUInt32LE( 0, 20 );                   // hash size
	header.writeUInt32LE( 0, 24 );                   // hash offset

	const nul = Buffer.from( [ 0 ] );
	writeFileSync(
		target,
		Buffer.concat( [
			header,
			idTable,
			strTable,
			...ids.flatMap( ( b ) => [ b, nul ] ),
			...strs.flatMap( ( b ) => [ b, nul ] ),
		] )
	);

	return n;
};

const poFiles = () =>
	existsSync( LANGUAGES )
		? readdirSync( LANGUAGES ).filter( ( f ) => f.endsWith( '.po' ) )
		: [];

const compile = () => {
	for ( const po of poFiles() ) {
		const entries = parsePo( readFileSync( join( LANGUAGES, po ), 'utf8' ) );
		const n = writeMo( entries, join( LANGUAGES, po.replace( /\.po$/, '.mo' ) ) );
		console.log( `${ po } -> ${ po.replace( /\.po$/, '.mo' ) } (${ n } translated)` );
	}
};

/**
 * Build the JSON catalogue for the admin app.
 *
 * WordPress looks for `{domain}-{locale}-{md5 of the script's relative path}.json`.
 * The path it hashes is the one registered with wp_register_script, which here
 * is build/index.js - get that wrong and the file is ignored in silence, which
 * is the single most common reason a translated plugin has an English admin
 * screen.
 */
const jsonCatalogue = () => {
	const handlePath = 'build/index.js';
	const hash = createHash( 'md5' ).update( handlePath ).digest( 'hex' );

	for ( const po of poFiles() ) {
		const locale = po.replace( `${ DOMAIN }-`, '' ).replace( /\.po$/, '' );
		const entries = parsePo( readFileSync( join( LANGUAGES, po ), 'utf8' ) );

		// Only what the JS actually uses. Shipping the PHP strings too would
		// double the size of a file the browser downloads on every admin page.
		const jsStrings = new Set();
		for ( const file of walk( join( ROOT, 'src' ) ) ) {
			for ( const e of extractFrom( file ) ) jsStrings.add( e.msgid );
		}

		const messages = { '': { domain: 'messages', lang: locale, 'plural-forms': 'nplurals=2; plural=(n != 1);' } };
		let n = 0;

		for ( const e of entries ) {
			if ( ! jsStrings.has( e.msgid ) ) continue;
			if ( e.msgid_plural ? ! e.plurals.some( Boolean ) : '' === e.msgstr ) continue;
			messages[ e.msgid ] = e.msgid_plural ? e.plurals : [ e.msgstr ];
			n++;
		}

		const target = join( LANGUAGES, `${ DOMAIN }-${ locale }-${ hash }.json` );
		writeFileSync(
			target,
			JSON.stringify( { 'translation-revision-date': new Date().toISOString(), generator: 'tools/i18n.mjs', domain: 'messages', locale_data: { messages } }, null, '\t' )
		);
		console.log( `${ po } -> ${ DOMAIN }-${ locale }-${ hash }.json (${ n } strings for the admin app)` );
	}
};

/**
 * Build a .po from the current strings plus a JSON map of translations.
 *
 *   node tools/i18n.mjs merge de_DE
 *
 * Translations live in tools/translations/{locale}.json as plain
 * "English": "German" pairs, which is the only form worth hand-editing: the
 * references, escaping and plural scaffolding are mechanical and are generated
 * here, so a translator never has to get PO syntax right and a string that
 * moves in the source does not have to be re-found.
 *
 * A translation whose English no longer appears anywhere is reported rather
 * than dropped in silence - it usually means the source was reworded and the
 * German needs looking at, not deleting.
 */
const merge = () => {
	const locale = process.argv[ 3 ];

	if ( ! locale ) {
		console.log( 'usage: node tools/i18n.mjs merge de_DE' );
		process.exit( 1 );
	}

	// Either one file, or a directory of parts. Parts exist because 500 strings
	// in a single JSON object is a file nobody can review a change to: split by
	// area, a diff shows which part of the plugin was retranslated.
	const dir = join( ROOT, 'tools', 'translations', locale );
	const one = join( ROOT, 'tools', 'translations', `${ locale }.json` );

	let map = {};

	if ( existsSync( dir ) && statSync( dir ).isDirectory() ) {
		for ( const part of readdirSync( dir ).filter( ( f ) => f.endsWith( '.json' ) ).sort() ) {
			Object.assign( map, JSON.parse( readFileSync( join( dir, part ), 'utf8' ) ) );
		}
	} else if ( existsSync( one ) ) {
		map = JSON.parse( readFileSync( one, 'utf8' ) );
	} else {
		console.log( `no translations at tools/translations/${ locale }/ or ${ locale }.json` );
		process.exit( 1 );
	}
	const strings = collect();

	const out = [
		`# German translation for Modern Mailer.`,
		'msgid ""',
		'msgstr ""',
		'"Project-Id-Version: Modern Mailer\\n"',
		'"MIME-Version: 1.0\\n"',
		'"Content-Type: text/plain; charset=UTF-8\\n"',
		'"Content-Transfer-Encoding: 8bit\\n"',
		`"Language: ${ locale }\\n"`,
		'"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
		`"X-Domain: ${ DOMAIN }\\n"`,
		'',
	];

	let translated = 0;
	const seen = new Set();

	for ( const { msgid, plural, refs } of strings.values() ) {
		const value = map[ msgid ];
		seen.add( msgid );

		out.push( `#: ${ refs.join( ' ' ) }` );
		out.push( `msgid "${ esc( msgid ) }"` );

		if ( plural ) {
			const forms = Array.isArray( value ) ? value : [ value || '', value || '' ];
			out.push( `msgid_plural "${ esc( plural ) }"` );
			out.push( `msgstr[0] "${ esc( forms[ 0 ] || '' ) }"` );
			out.push( `msgstr[1] "${ esc( forms[ 1 ] || '' ) }"` );
			if ( forms[ 0 ] ) translated++;
		} else {
			out.push( `msgstr "${ esc( typeof value === 'string' ? value : '' ) }"` );
			if ( typeof value === 'string' && '' !== value ) translated++;
		}

		out.push( '' );
	}

	writeFileSync( join( LANGUAGES, `${ DOMAIN }-${ locale }.po` ), out.join( '\n' ) );

	const orphaned = Object.keys( map ).filter( ( k ) => ! seen.has( k ) );

	console.log( `${ translated }/${ strings.size } translated -> languages/${ DOMAIN }-${ locale }.po` );

	if ( orphaned.length ) {
		console.log( `\n${ orphaned.length } translation(s) no longer match any source string:` );
		for ( const k of orphaned.slice( 0, 20 ) ) console.log( '   ' + JSON.stringify( k.slice( 0, 80 ) ) );
	}
};

const check = () => {
	const strings = collect();

	for ( const po of poFiles() ) {
		const entries = parsePo( readFileSync( join( LANGUAGES, po ), 'utf8' ) );
		const done = new Set( entries.filter( ( e ) => '' !== e.msgstr || e.plurals.some( Boolean ) ).map( ( e ) => e.msgid ) );
		const missing = [ ...strings.values() ].filter( ( s ) => ! done.has( s.msgid ) );

		console.log( `${ po }: ${ done.size }/${ strings.size } translated, ${ missing.length } missing` );
		for ( const s of missing.slice( 0, 40 ) ) console.log( '   ' + JSON.stringify( s.msgid.slice( 0, 90 ) ) );
		if ( missing.length > 40 ) console.log( `   ... and ${ missing.length - 40 } more` );
	}
};

const command = process.argv[ 2 ];
( { extract, compile, json: jsonCatalogue, check, merge }[ command ] || (() => {
	console.log( 'usage: node tools/i18n.mjs extract|compile|json|check' );
	process.exit( 1 );
} ) )();
