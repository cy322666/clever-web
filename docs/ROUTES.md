# Routes overview

## Web routes (`routes/web.php`)
| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/` | Redirects to Filament login (`filament.app.auth.login`). |
| GET | `/clever/bayers/forms/pay` | Livewire page for Clever Bayers payment form. |

Source: `routes/web.php`.

## API routes (`routes/api.php`)
All routes below are defined under middleware `user.active` and `user.inputs` unless noted otherwise.

### Bizon
| Method | Path | Name |
| --- | --- | --- |
| POST | `/api/bizon/hook/{user:uuid}` | `bizon.hook` |
| POST | `/api/bizon/form/{user:uuid}` | `bizon.form` |

### GetCourse
| Method | Path | Name |
| --- | --- | --- |
| GET | `/api/getcourse/orders/{user:uuid}/{template}` | `getcourse.order` |
| GET | `/api/getcourse/forms/{user:uuid}/{form}` | `getcourse.form` |

### Tilda
| Method | Path | Name |
| --- | --- | --- |
| POST | `/api/tilda/hook/{user:uuid}/{site}` | `tilda.hook` |

### Distribution
| Method | Path | Name |
| --- | --- | --- |
| POST | `/api/distribution/hook/{user:uuid}/{template}` | `distribution.hook` |

### AlfaCRM
| Method | Path | Name |
| --- | --- | --- |
| POST | `/api/alfacrm/record/{user:uuid}` | `alfacrm.record` |
| POST | `/api/alfacrm/came/{user:uuid}` | `alfacrm.came` |
| POST | `/api/alfacrm/omission/{user:uuid}` | `alfacrm.omission` |

### ActiveLead, Dadata, Docs, YClients
| Method | Path | Name |
| --- | --- | --- |
| POST | `/api/active-leads/{user:uuid}` | `active-leads.hook` |
| POST | `/api/data/{user:uuid}` | `data.hook` |
| POST | `/api/docs/{user:uuid}/{doc}` | `doc.hook` |
| POST | `/api/yclients/hook/{user:uuid}` | `yclients.hook` |

### amoCRM
amoCRM routes are not inside the `user.active` / `user.inputs` group.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/api/amocrm/secrets` | Receives integration secrets. |
| GET | `/api/amocrm/redirect` | OAuth redirect handler. |
| POST | `/api/amocrm/install` | Install hook from frontend. |
| GET | `/api/amocrm/edtechindustry/redirect` | OAuth redirect for edtechindustry app. |
| POST | `/api/amocrm/edtechindustry/form` | Form hook for edtechindustry app. |
