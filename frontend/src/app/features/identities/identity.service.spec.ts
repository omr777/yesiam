import { TestBed } from '@angular/core/testing';

import {
  provideHttpClient,
  HttpErrorResponse,
} from '@angular/common/http';

import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';

import {
  IdentityService,
} from './identity.service';

import type {
  CreateIdentityRequest,
  CreateIdentityResponse,
  PaginatedIdentitiesResponse,
} from './identity.service';

/*
|--------------------------------------------------------------------------
| YESIAM - IDENTITY SERVICE TESTS
|--------------------------------------------------------------------------
|
| FASE 19.5.2
|
| Pruebas de:
|
| GET /api/users
| POST /api/users
|
| Validamos:
|
| - Listado de identidades.
| - Filtros y paginación.
| - Autenticación mediante sesión.
| - Creación de identidades.
| - Protección de campos administrativos.
| - Propagación de errores HTTP.
|
| Ninguna prueba utiliza MySQL.
|
*/

describe('IdentityService', () => {

  let service: IdentityService;
  let httpMock: HttpTestingController;

  /*
  |--------------------------------------------------------------------------
  | DATOS SIMULADOS DEL DIRECTORIO
  |--------------------------------------------------------------------------
  */

  const mockResponse: PaginatedIdentitiesResponse = {
    data: [
      {
        id: 1,
        name: 'Ana García',
        email: 'ana@example.test',
        status: 'active',
        department_id: 2,
      },
      {
        id: 2,
        name: 'Carlos López',
        email: 'carlos@example.test',
        status: 'suspended',
        department_id: null,
      },
    ],
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 2,
    from: 1,
    to: 2,
    next_page_url: null,
    prev_page_url: null,
  };

  /*
  |--------------------------------------------------------------------------
  | DATOS SIMULADOS DEL JOINER
  |--------------------------------------------------------------------------
  |
  | Angular únicamente envía:
  |
  | - name
  | - email
  | - department_id
  |
  | Laravel determina:
  |
  | - status = suspended
  | - role = employee
  | - password aleatoria
  |
  */

  const createPayload: CreateIdentityRequest = {
    name: 'Elena Martínez',
    email: 'elena@example.test',
    department_id: 3,
  };

  const mockCreateResponse: CreateIdentityResponse = {
    message: 'Identidad creada correctamente.',
    data: {
      id: 15,
      name: 'Elena Martínez',
      email: 'elena@example.test',
      status: 'suspended',
      department_id: 3,
      roles: ['employee'],
    },
  };

  /*
  |--------------------------------------------------------------------------
  | PREPARACIÓN DE PRUEBAS
  |--------------------------------------------------------------------------
  */

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    service = TestBed.inject(IdentityService);

    httpMock = TestBed.inject(
      HttpTestingController
    );
  });

  afterEach(() => {
    /*
     * Garantiza que no queden
     * solicitudes simuladas pendientes.
     */
    httpMock.verify();
  });

  /*
  |--------------------------------------------------------------------------
  | SECCIÓN A - CONSULTA DE IDENTIDADES
  |--------------------------------------------------------------------------
  */

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 1 - CREAR SERVICIO
  |--------------------------------------------------------------------------
  */

  it('should create the service', () => {
    expect(service).toBeTruthy();

    httpMock.expectNone('/api/users');
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 2 - PAGINACIÓN PREDETERMINADA
  |--------------------------------------------------------------------------
  */

  it('should request identities with default pagination', () => {
    let response: PaginatedIdentitiesResponse | undefined;

    service.list().subscribe((result) => {
      response = result;
    });

    const request = httpMock.expectOne(
      (req) =>
        req.method === 'GET' &&
        req.url === '/api/users'
    );

    expect(
      request.request.params.get('page')
    ).toBe('1');

    expect(
      request.request.params.get('per_page')
    ).toBe('15');

    expect(
      request.request.params.has('search')
    ).toBe(false);

    expect(
      request.request.params.has('status')
    ).toBe(false);

    request.flush(mockResponse);

    expect(response).toEqual(mockResponse);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 3 - CREDENCIALES Y JSON
  |--------------------------------------------------------------------------
  */

  it('should include credentials and accept JSON', () => {
    service.list().subscribe();

    const request = httpMock.expectOne(
      (req) => req.url === '/api/users'
    );

    expect(request.request.method).toBe('GET');

    expect(
      request.request.withCredentials
    ).toBe(true);

    expect(
      request.request.headers.get('Accept')
    ).toBe('application/json');

    expect(
      request.request.headers.has('Authorization')
    ).toBe(false);

    request.flush(mockResponse);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 4 - BÚSQUEDA
  |--------------------------------------------------------------------------
  */

  it('should trim and send the search parameter', () => {
    service.list({
      search: '  Ana García  ',
    }).subscribe();

    const request = httpMock.expectOne(
      (req) => req.url === '/api/users'
    );

    expect(
      request.request.params.get('search')
    ).toBe('Ana García');

    request.flush(mockResponse);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 5 - FILTRO POR ESTADO
  |--------------------------------------------------------------------------
  */

  it('should send the identity status filter', () => {
    service.list({
      status: 'suspended',
    }).subscribe();

    const request = httpMock.expectOne(
      (req) => req.url === '/api/users'
    );

    expect(
      request.request.params.get('status')
    ).toBe('suspended');

    request.flush(mockResponse);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 6 - PAGINACIÓN PERSONALIZADA
  |--------------------------------------------------------------------------
  */

  it('should send custom pagination values', () => {
    service.list({
      page: 3,
      perPage: 25,
    }).subscribe();

    const request = httpMock.expectOne(
      (req) => req.url === '/api/users'
    );

    expect(
      request.request.params.get('page')
    ).toBe('3');

    expect(
      request.request.params.get('per_page')
    ).toBe('25');

    request.flush(mockResponse);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 7 - FILTROS COMBINADOS
  |--------------------------------------------------------------------------
  */

  it('should combine all filters correctly', () => {
    service.list({
      search: 'Carlos',
      status: 'active',
      page: 2,
      perPage: 10,
    }).subscribe();

    const request = httpMock.expectOne(
      (req) => req.url === '/api/users'
    );

    expect(
      request.request.params.get('search')
    ).toBe('Carlos');

    expect(
      request.request.params.get('status')
    ).toBe('active');

    expect(
      request.request.params.get('page')
    ).toBe('2');

    expect(
      request.request.params.get('per_page')
    ).toBe('10');

    request.flush(mockResponse);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 8 - OMITIR FILTROS VACÍOS
  |--------------------------------------------------------------------------
  */

  it('should omit empty search and status filters', () => {
    service.list({
      search: '   ',
      status: '',
    }).subscribe();

    const request = httpMock.expectOne(
      (req) => req.url === '/api/users'
    );

    expect(
      request.request.params.has('search')
    ).toBe(false);

    expect(
      request.request.params.has('status')
    ).toBe(false);

    request.flush(mockResponse);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 9 - ERROR 401 EN LISTADO
  |--------------------------------------------------------------------------
  */

  it('should propagate unauthorized errors', () => {
    let receivedError: HttpErrorResponse | undefined;

    service.list().subscribe({
      error: (error: HttpErrorResponse) => {
        receivedError = error;
      },
    });

    const request = httpMock.expectOne(
      (req) => req.url === '/api/users'
    );

    request.flush(
      {
        message: 'Unauthenticated.',
      },
      {
        status: 401,
        statusText: 'Unauthorized',
      }
    );

    expect(receivedError).toBeInstanceOf(
      HttpErrorResponse
    );

    expect(receivedError?.status).toBe(401);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 10 - ERROR 403 EN LISTADO
  |--------------------------------------------------------------------------
  */

  it('should propagate forbidden errors', () => {
    let receivedError: HttpErrorResponse | undefined;

    service.list().subscribe({
      error: (error: HttpErrorResponse) => {
        receivedError = error;
      },
    });

    const request = httpMock.expectOne(
      (req) => req.url === '/api/users'
    );

    request.flush(
      {
        message: 'This action is unauthorized.',
      },
      {
        status: 403,
        statusText: 'Forbidden',
      }
    );

    expect(receivedError).toBeInstanceOf(
      HttpErrorResponse
    );

    expect(receivedError?.status).toBe(403);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 11 - ERROR 422 EN LISTADO
  |--------------------------------------------------------------------------
  */

  it('should propagate validation errors', () => {
    let receivedError: HttpErrorResponse | undefined;

    service.list({
      perPage: 101,
    }).subscribe({
      error: (error: HttpErrorResponse) => {
        receivedError = error;
      },
    });

    const request = httpMock.expectOne(
      (req) => req.url === '/api/users'
    );

    expect(
      request.request.params.get('per_page')
    ).toBe('101');

    request.flush(
      {
        message: 'The per page field must not be greater than 100.',
        errors: {
          per_page: [
            'The per page field must not be greater than 100.',
          ],
        },
      },
      {
        status: 422,
        statusText: 'Unprocessable Content',
      }
    );

    expect(receivedError?.status).toBe(422);

    expect(
      receivedError?.error.errors.per_page
    ).toBeDefined();
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 12 - ERROR DE RED EN LISTADO
  |--------------------------------------------------------------------------
  */

  it('should propagate network errors', () => {
    let receivedError: HttpErrorResponse | undefined;

    service.list().subscribe({
      error: (error: HttpErrorResponse) => {
        receivedError = error;
      },
    });

    const request = httpMock.expectOne(
      (req) => req.url === '/api/users'
    );

    request.error(
      new ProgressEvent('error')
    );

    expect(receivedError).toBeInstanceOf(
      HttpErrorResponse
    );

    expect(receivedError?.status).toBe(0);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 13 - NO OCULTAR ERROR DE AUTORIZACIÓN
  |--------------------------------------------------------------------------
  */

  it('should not return an empty list on authorization failure', () => {
    let successCalled = false;
    let errorCalled = false;

    service.list().subscribe({
      next: () => {
        successCalled = true;
      },
      error: (error: HttpErrorResponse) => {
        errorCalled = error.status === 403;
      },
    });

    const request = httpMock.expectOne(
      (req) => req.url === '/api/users'
    );

    request.flush(
      {
        message: 'Forbidden',
      },
      {
        status: 403,
        statusText: 'Forbidden',
      }
    );

    expect(successCalled).toBe(false);
    expect(errorCalled).toBe(true);
  });

  /*
  |--------------------------------------------------------------------------
  | SECCIÓN B - JOINER: CREACIÓN DE IDENTIDADES
  |--------------------------------------------------------------------------
  */

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 14 - CREACIÓN EXITOSA
  |--------------------------------------------------------------------------
  |
  | Verificamos:
  |
  | - Método POST.
  | - Endpoint correcto.
  | - Datos enviados.
  | - Respuesta HTTP 201.
  | - Identidad suspendida devuelta por Laravel.
  |
  */

  it('should create an identity and return the Laravel response', () => {
    let response: CreateIdentityResponse | undefined;

    service.create(createPayload).subscribe({
      next: (result) => {
        response = result;
      },
    });

    const request = httpMock.expectOne(
      (req) =>
        req.method === 'POST' &&
        req.url === '/api/users'
    );

    expect(request.request.body).toEqual(
      createPayload
    );

    request.flush(
      mockCreateResponse,
      {
        status: 201,
        statusText: 'Created',
      }
    );

    expect(response).toEqual(
      mockCreateResponse
    );

    expect(response?.data.status).toBe(
      'suspended'
    );

    expect(response?.data.roles).toEqual([
      'employee',
    ]);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 15 - PROTECCIÓN DE CAMPOS
  |--------------------------------------------------------------------------
  |
  | Angular no debe enviar:
  |
  | - password
  | - status
  | - roles
  | - id
  |
  | Esos datos los administra Laravel.
  |
  */

  it('should send only the allowed Joiner fields', () => {
    service.create(createPayload).subscribe();

    const request = httpMock.expectOne(
      (req) =>
        req.method === 'POST' &&
        req.url === '/api/users'
    );

    const body = request.request.body as Record<
      string,
      unknown
    >;

    expect(Object.keys(body).sort()).toEqual([
      'department_id',
      'email',
      'name',
    ]);

    expect(body['name']).toBe(
      'Elena Martínez'
    );

    expect(body['email']).toBe(
      'elena@example.test'
    );

    expect(body['department_id']).toBe(3);

    expect(body['password']).toBeUndefined();
    expect(body['status']).toBeUndefined();
    expect(body['roles']).toBeUndefined();
    expect(body['id']).toBeUndefined();

    request.flush(
      mockCreateResponse,
      {
        status: 201,
        statusText: 'Created',
      }
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 16 - SESIÓN DE SANCTUM
  |--------------------------------------------------------------------------
  |
  | La creación debe utilizar cookies
  | de sesión, sin agregar tokens Bearer.
  |
  */

  it('should create identities using session credentials', () => {
    service.create(createPayload).subscribe();

    const request = httpMock.expectOne(
      (req) =>
        req.method === 'POST' &&
        req.url === '/api/users'
    );

    expect(
      request.request.withCredentials
    ).toBe(true);

    expect(
      request.request.headers.get('Accept')
    ).toBe('application/json');

    expect(
      request.request.headers.has('Authorization')
    ).toBe(false);

    request.flush(
      mockCreateResponse,
      {
        status: 201,
        statusText: 'Created',
      }
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 17 - OBSERVABLE SIN SUSCRIPCIÓN
  |--------------------------------------------------------------------------
  |
  | Crear el Observable no debe disparar
  | la solicitud hasta que alguien
  | se suscriba.
  |
  */

  it('should not send a create request before subscription', () => {
    const operation = service.create(
      createPayload
    );

    httpMock.expectNone(
      (req) =>
        req.method === 'POST' &&
        req.url === '/api/users'
    );

    operation.subscribe();

    const request = httpMock.expectOne(
      (req) =>
        req.method === 'POST' &&
        req.url === '/api/users'
    );

    request.flush(
      mockCreateResponse,
      {
        status: 201,
        statusText: 'Created',
      }
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 18 - ERROR 401
  |--------------------------------------------------------------------------
  |
  | Una sesión inválida no debe
  | interpretarse como una creación exitosa.
  |
  */

  it('should propagate unauthorized errors during identity creation', () => {
    let receivedError: HttpErrorResponse | undefined;
    let successCalled = false;

    service.create(createPayload).subscribe({
      next: () => {
        successCalled = true;
      },
      error: (error: HttpErrorResponse) => {
        receivedError = error;
      },
    });

    const request = httpMock.expectOne(
      (req) =>
        req.method === 'POST' &&
        req.url === '/api/users'
    );

    request.flush(
      {
        message: 'Unauthenticated.',
      },
      {
        status: 401,
        statusText: 'Unauthorized',
      }
    );

    expect(successCalled).toBe(false);

    expect(receivedError).toBeInstanceOf(
      HttpErrorResponse
    );

    expect(receivedError?.status).toBe(401);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 19 - ERROR 403
  |--------------------------------------------------------------------------
  |
  | Un usuario sin users.manage
  | no puede crear identidades.
  |
  */

  it('should propagate forbidden errors during identity creation', () => {
    let receivedError: HttpErrorResponse | undefined;

    service.create(createPayload).subscribe({
      error: (error: HttpErrorResponse) => {
        receivedError = error;
      },
    });

    const request = httpMock.expectOne(
      (req) =>
        req.method === 'POST' &&
        req.url === '/api/users'
    );

    request.flush(
      {
        message: 'This action is unauthorized.',
      },
      {
        status: 403,
        statusText: 'Forbidden',
      }
    );

    expect(receivedError?.status).toBe(403);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 20 - ERROR 419
  |--------------------------------------------------------------------------
  |
  | Laravel puede rechazar una escritura
  | cuando el token CSRF no es válido.
  |
  | El servicio debe propagar ese error.
  |
  */

  it('should propagate CSRF errors during identity creation', () => {
    let receivedError: HttpErrorResponse | undefined;

    service.create(createPayload).subscribe({
      error: (error: HttpErrorResponse) => {
        receivedError = error;
      },
    });

    const request = httpMock.expectOne(
      (req) =>
        req.method === 'POST' &&
        req.url === '/api/users'
    );

    request.flush(
      {
        message: 'CSRF token mismatch.',
      },
      {
        status: 419,
        statusText: 'Page Expired',
      }
    );

    expect(receivedError?.status).toBe(419);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 21 - ERROR 422
  |--------------------------------------------------------------------------
  |
  | Ejemplos:
  |
  | - Correo duplicado.
  | - Nombre inválido.
  | - Departamento inexistente.
  | - Departamento inactivo.
  |
  | Laravel devuelve los errores de validación.
  |
  */

  it('should propagate Laravel validation errors during identity creation', () => {
    let receivedError: HttpErrorResponse | undefined;

    service.create(createPayload).subscribe({
      error: (error: HttpErrorResponse) => {
        receivedError = error;
      },
    });

    const request = httpMock.expectOne(
      (req) =>
        req.method === 'POST' &&
        req.url === '/api/users'
    );

    request.flush(
      {
        message: 'The email has already been taken.',
        errors: {
          email: [
            'The email has already been taken.',
          ],
        },
      },
      {
        status: 422,
        statusText: 'Unprocessable Content',
      }
    );

    expect(receivedError?.status).toBe(422);

    expect(
      receivedError?.error.errors.email
    ).toBeDefined();

    expect(
      receivedError?.error.errors.email.length
    ).toBe(1);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 22 - ERROR DE CONEXIÓN
  |--------------------------------------------------------------------------
  |
  | Si Laravel no está disponible,
  | el servicio debe entregar el error.
  |
  */

  it('should propagate network errors during identity creation', () => {
    let receivedError: HttpErrorResponse | undefined;

    service.create(createPayload).subscribe({
      error: (error: HttpErrorResponse) => {
        receivedError = error;
      },
    });

    const request = httpMock.expectOne(
      (req) =>
        req.method === 'POST' &&
        req.url === '/api/users'
    );

    request.error(
      new ProgressEvent('error')
    );

    expect(receivedError).toBeInstanceOf(
      HttpErrorResponse
    );

    expect(receivedError?.status).toBe(0);
  });

});
