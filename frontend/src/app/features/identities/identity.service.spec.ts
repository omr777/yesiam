import { TestBed } from '@angular/core/testing';

import {
  provideHttpClient,
} from '@angular/common/http';

import {
  HttpErrorResponse,
} from '@angular/common/http';

import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';

import {
  IdentityService,
  PaginatedIdentitiesResponse,
} from './identity.service';

describe('IdentityService', () => {
  let service: IdentityService;
  let httpMock: HttpTestingController;

  /*
  |--------------------------------------------------------------------------
  | DATOS SIMULADOS
  |--------------------------------------------------------------------------
  |
  | Nunca utilizamos usuarios reales.
  | Estas pruebas no acceden a MySQL.
  |
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
     * Comprueba que ninguna solicitud
     * HTTP simulada quede pendiente.
     */
    httpMock.verify();
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 1
  | EL SERVICIO PUEDE CREARSE
  |--------------------------------------------------------------------------
  */

  it('should create the service', () => {
    expect(service).toBeTruthy();

    /*
     * Crear el servicio no debe
     * realizar solicitudes automáticamente.
     */
    httpMock.expectNone('/api/users');
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 2
  | CONSULTA CON PAGINACIÓN PREDETERMINADA
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

    expect(request.request.params.get('page'))
      .toBe('1');

    expect(request.request.params.get('per_page'))
      .toBe('15');

    expect(request.request.params.has('search'))
      .toBe(false);

    expect(request.request.params.has('status'))
      .toBe(false);

    request.flush(mockResponse);

    expect(response).toEqual(mockResponse);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 3
  | SESIÓN DE SANCTUM Y CABECERAS HTTP
  |--------------------------------------------------------------------------
  */

  it('should include credentials and accept JSON', () => {
    service.list().subscribe();

    const request = httpMock.expectOne(
      (req) => req.url === '/api/users'
    );

    expect(request.request.method).toBe('GET');

    expect(request.request.withCredentials)
      .toBe(true);

    expect(
      request.request.headers.get('Accept')
    ).toBe('application/json');

    /*
     * No utilizamos Authorization Bearer
     * porque YESIAM usa cookies de sesión.
     */
    expect(
      request.request.headers.has('Authorization')
    ).toBe(false);

    request.flush(mockResponse);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 4
  | BÚSQUEDA POR NOMBRE O CORREO
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
  | PRUEBA 5
  | FILTRAR POR ESTADO
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
  | PRUEBA 6
  | PAGINACIÓN PERSONALIZADA
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
  | PRUEBA 7
  | COMBINAR BÚSQUEDA, ESTADO Y PAGINACIÓN
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
  | PRUEBA 8
  | OMITIR FILTROS VACÍOS
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
  | PRUEBA 9
  | ERROR 401 - NO AUTENTICADO
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
  | PRUEBA 10
  | ERROR 403 - SIN PERMISOS RBAC
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
  | PRUEBA 11
  | ERROR 422 - FILTROS INVÁLIDOS
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

    expect(receivedError?.error.errors.per_page)
      .toBeDefined();
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 12
  | ERROR DE RED
  |--------------------------------------------------------------------------
  |
  | Si Laravel no responde, Angular debe
  | recibir el error para mostrar un estado
  | de servicio no disponible.
  |
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
  | PRUEBA 13
  | NO TRANSFORMAR UN ERROR EN UNA LISTA VACÍA
  |--------------------------------------------------------------------------
  |
  | Es importante distinguir entre:
  |
  | - 200 con cero identidades.
  | - 403 por falta de permisos.
  |
  | Devolver [] ante 403 ocultaría el problema
  | de autorización y engañaría a la UI.
  |
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
});
