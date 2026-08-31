# Construction Intelligence

Source-agnostic. Runs after a connector returns a normalized profile.

## Pipeline

1. Relevance: is this construction-related? If no → discard / mark irrelevant. Do not save as a lead.
2. Classify business category.
3. Detect project / purchase intent.
4. Extract public contacts.
5. Detect location.
6. Emit scoring signals.

## Keyword library

Administrators CRUD keywords in groups (General, Swahili, Architecture, Engineering, Property, Renovation, Interior, Roofing, Plumbing, Electrical, Finishing). Not hardcoded in collectors.

## Classification categories

Contractor, Construction Company, Architect, Engineer, Property Developer, Real Estate Company, Interior Designer, Plumber, Electrician, Roofing Contractor, Painter, Tile Installer, Carpenter, Aluminium / Glass, Construction Consultant, Hardware Business, Construction Project, Individual Building Customer, Unknown Construction Prospect.

## Intent

High-value English and Swahili phrases (new project, tunajenga, BOQ, materials needed, …) increase intent score.
