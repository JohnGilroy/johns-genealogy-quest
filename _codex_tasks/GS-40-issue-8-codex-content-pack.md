# GS-40 Newsletter Issue 8 — July 2026 Codex Content Pack

## Purpose

Update the John’s Genealogy Quest website repository for Newsletter Issue 8 — July 2026.

The newsletter announces that George Blacklaw’s story is now available on the website to read and download. The content and tone should follow the existing June newsletter / Issue 7 style.

## Important correction

In `data/newsletter/issues/2026-08.json`, correct:

```json
"planned_publication_date": "3 July 2020"
```

to:

```json
"planned_publication_date": "3 July 2026"
```

## Files to create or update

Create or update these website files, following the repository’s existing newsletter conventions:

```text
newsletters/2026/08/index.html
newsletters/2026/08/newsletter-8.html
newsletters/index.html
```

Only update `index.html` at the website root if the repository already has a current/latest newsletter or recent update section that should point to Issue 8.

## Source files to use from the repository

Use the existing website repository files, not invented paths:

- Use `newsletters/2026/07/newsletter-7.html` as the primary style and layout model.
- Use the George Blacklaw story page as the factual source for the main item.
- Use the George Blacklaw PDF/download link from the story page or repository.
- Use existing images already associated with George Blacklaw’s story, if the Issue 7 newsletter layout supports an image feature.
- Preserve existing site base paths, metadata style, footer wording, responsive email layout and archive conventions.

## Newsletter metadata

- Issue: 8
- Month: July 2026
- Main topic: George Blacklaw’s story is now available to read and download.
- Secondary topic: A light-hearted note that John is now a publisher and author of William James Gilroy’s story in paperback edition — prices on application.
- Tone: friendly, personal, slightly teasing, evidence-led, written for family and interested non-specialists.
- Main aim: intrigue readers so they click through to George Blacklaw’s story, without giving away the ending.

## Subject line

Use this subject line unless the existing newsletter system requires a different field:

```text
John’s Genealogy Quest — George Blacklaw’s Story Is Now Online
```

## Preview text

Use this preview text if the layout supports it:

```text
A new family story is ready to read — George Blacklaw’s life, loves and final chapter, plus a small publishing announcement.
```

## Main newsletter copy

Use the following editorial copy, adapting only where needed to fit the exact Issue 7 HTML structure.

### Main heading

```text
George Blacklaw’s Story Is Now Ready to Read
```

### Introduction

```text
This month’s newsletter marks the arrival of a new story on John’s Genealogy Quest: the life of my great-great-grandfather George Blacklaw.

George’s story is not a simple march from birth to death. It moves across rural Scotland, through changing relationships, hard agricultural lives, family complications and a final chapter that raises as many human questions as genealogical ones.
```

### Main feature section

Suggested heading:

```text
A life told backwards from the end
```

Suggested copy:

```text
Rather than beginning neatly with a birth certificate, George Blacklaw’s story opens at the end — with his death at Duntarvie, near Linlithgow, in February 1899.

From there the trail leads back through a life shaped by work, movement and relationships across Kincardineshire, Forfarshire and Midlothian. Catherine Strachan, Caroline Mathieson and Marion Kerr each appear in the record, and each changes the shape of the story.

The result is part family history, part detective work and part human drama. It is a story of agricultural labourers, mill workers, remarriage, uncertainty, children, place, memory and the awkward gaps that records do not always explain.

I will not give away the full outcome here. The pleasure is in following the evidence as it unfolds — and in seeing how one apparently ordinary rural life can become anything but ordinary once the records are placed side by side.
```

### Call to action

Use the exact George Blacklaw story URL from the repository. If there are separate read-online and PDF download links, include both.

Suggested wording:

```text
George Blacklaw’s story is now available on the website to read online and download.
```

Button/link text:

```text
Read George Blacklaw’s story
```

If a PDF link is present, use:

```text
Download the PDF version
```

### Secondary item

Suggested heading:

```text
Apparently, I am now a publisher
```

Suggested copy:

```text
In other news, William James Gilroy’s story has now escaped the screen and appeared in paperback form.

This means I can now, with only slight exaggeration, describe myself as both author and publisher. Copies are, of course, available at prices on application — though family rates may apply, depending on how kind you are about the proofreading.
```

### Closing

```text
As always, the website remains a work in progress. Each new story seems to tidy up one part of the family history while opening three more questions somewhere else.

We hope you enjoy George Blacklaw’s story, and we would be delighted to hear from anyone who spots a connection, remembers a family detail, or simply wants to comment on the latest chapter.

John & Chris
```

## Archive entry

Add Issue 8 to the newsletter archive.

Suggested archive wording:

```text
George Blacklaw’s story is now available to read and download, with a light-hearted note on becoming a paperback author and publisher.
```

The archive link should point to:

```text
newsletters/2026/08/
```

## Issue landing page metadata

For `newsletters/2026/08/index.html`, use the existing issue landing-page structure and redirect to `newsletter-8.html`.

Suggested title:

```text
Issue 8 — July 2026 Newsletter · John’s Genealogy Quest
```

Suggested Open Graph description:

```text
George Blacklaw’s story is now available on John’s Genealogy Quest, with a light-hearted publishing update from John.
```

## Facebook post

Create a Facebook post draft using this copy:

```text
This month’s John’s Genealogy Quest newsletter is now available.

The main feature is the new story of my great-great-grandfather George Blacklaw — a life traced through rural Scotland, family complications, hard work, changing relationships and a final chapter that starts the story at the end.

I have kept the newsletter deliberately short, because the point is to tempt you towards the full story rather than spoil it.

There is also a small, entirely serious announcement that I am now technically a paperback author and publisher. Prices on application, naturally.

Read the July newsletter here:
[ISSUE_8_PUBLIC_URL]
```

Replace `[ISSUE_8_PUBLIC_URL]` with the correct public URL or relative URL convention used by the site.

## Checks to perform

After editing the files:

1. Confirm `newsletters/2026/08/index.html` redirects or links to `newsletter-8.html` correctly.
2. Confirm `newsletters/2026/08/newsletter-8.html` is readable online in a browser.
3. Confirm `newsletters/index.html` lists Issue 8 and links to `newsletters/2026/08/`.
4. Confirm all George Blacklaw story and PDF/download links resolve locally.
5. Confirm no local filesystem paths are present.
6. Confirm no unrelated website pages were changed.
7. Confirm filename case is GitHub Pages safe.
8. Confirm the issue date/year is 2026, not 2020.

## Commit message suggestion

```text
Publish Newsletter Issue 8 for July 2026
```
