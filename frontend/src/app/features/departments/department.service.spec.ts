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
  DepartmentService,
} from './department.service';

import type {
  Department,
  DepartmentsResponse,
} from './department.service';

/*
|--------------------------------------------------------------------------
| YESIAM - DEPARTMENT SERVICE TESTS
|--------------------------------------------------------------------------
|
| FASE 19.5.3.2
|
| Pruebas de:
|
|   GET /api/departments
|
| Validamos:
|
| - Consulta del catálogo completo.
| - Filtrado de departamentos activos.
| - Compatibilidad con is_active booleano o 0/1.
| - Orden devuelto por Laravel.
| - Autenticación mediante sesión de Sanctum.
| - Propagación de errores HTTP.
|
| IMPORTANTE:
|
| El filtro de Angular solo mejora la experiencia.
| Laravel vuelve a validar el departamento en
| UserController::store().
|
| Ninguna prueba utiliza MySQL.
|
*/

describe('DepartmentService', () => {

  let service: DepartmentService;
  let httpMock: HttpTestingController;

  const endpoint = '/api/departments';

  /*
  |--------------------------------------------------------------------------
  | DATOS SIMULADOS
  |--------------------------------------------------------------------------
  |
  | Laravel devuelve los departamentos ordenados
  | por nombre dentro de la propiedad data.
  |
  | Mezclamos estados activos e inactivos, en
  | formato booleano y entero, para validar
  | ambos tipos de serialización.
  |
  */

  const mockResponse: DepartmentsResponse = {
    data: [
      {
        id: 1,
        name: 'Administración',
        code: 'ADMIN',
        is_active: true,
      },
      {
        id: 2,
        name: 'Finanzas',
        code: 'FIN',
        is_active: false,
      },
      {
        id: 3,
        name: 'Recursos Humanos',
        code: 'RH',
        is_active: 1,
      },
      {
        id: 4,
        name: 'Tecnología',
        code: 'TI',
        is_active: 0,
      },
      {
        id: 5,
        name: 'Ventas',
        code: 'VTA',
        is_active: true,
      },
    ],
  };

  /*
   * Respuesta donde ningún departamento
   * se encuentra activo.
   */
  const inactiveOnlyResponse: DepartmentsResponse = {
    data: [
      {
        id: 2,
        name: 'Finanzas',
        code: 'FIN',
        is_active: false,
      },
      {
        id: 4,
        name: 'Tecnología',
        code: 'TI',
        is_active: 0,
      },
    ],
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

    service = TestBed.inject(DepartmentService);

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
  | PRUEBA 1 - CREAR SERVICIO
  |--------------------------------------------------------------------------
  */

  it('should create the service', () => {
    expect(service).toBeTruthy();

    httpMock.expectNone(endpoint);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 2 - CATÁLOGO COMPLETO
  |--------------------------------------------------------------------------
  |
  | list() devuelve la respuesta de Laravel
  | sin modificarla, incluidos los inactivos.
  |
  */

  it('should request the full department catalog', () => {
    let response: DepartmentsResponse | undefined;

    service.list().subscribe((result) => {
      response = result;
    });

    const request = httpMock.expectOne(
      (req) =>
        req.method === 'GET' &&
        req.url === endpoint
    );

    expect(
      request.request.params.keys().length
    ).toBe(0);

    request.flush(mockResponse);

    expect(response).toEqual(mockResponse);
    expect(response?.data.length).toBe(5);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 3 - SESIÓN DE SANCTUM
  |--------------------------------------------------------------------------
  |
  | Cookies de sesión y JSON.
  | Sin tokens Bearer.
  |
  */

  it('should use session credentials and accept JSON', () => {
    service.listActive().subscribe();

    const request = httpMock.expectOne(
      (req) => req.url === endpoint
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
  | PRUEBA 4 - SOLO DEPARTAMENTOS ACTIVOS
  |--------------------------------------------------------------------------
  |
  | true y 1 se consideran activos.
  | false y 0 se excluyen.
  |
  */

  it('should return only active departments', () => {
    let result: Department[] | undefined;

    service.listActive().subscribe((departments) => {
      result = departments;
    });

    httpMock
      .expectOne((req) => req.url === endpoint)
      .flush(mockResponse);

    expect(result?.length).toBe(3);

    expect(
      result?.some((department) => department.id === 2)
    ).toBe(false);

    expect(
      result?.some((department) => department.id === 4)
    ).toBe(false);

    expect(
      result?.every(
        (department) =>
          department.is_active === true ||
          department.is_active === 1
      )
    ).toBe(true);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 5 - CONSERVAR ORDEN DE LARAVEL
  |--------------------------------------------------------------------------
  |
  | El backend ordena por nombre.
  | Angular no debe alterar ese orden.
  |
  */

  it('should preserve the order returned by Laravel', () => {
    let result: Department[] | undefined;

    service.listActive().subscribe((departments) => {
      result = departments;
    });

    httpMock
      .expectOne((req) => req.url === endpoint)
      .flush(mockResponse);

    expect(
      result?.map((department) => department.name)
    ).toEqual([
      'Administración',
      'Recursos Humanos',
      'Ventas',
    ]);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 6 - SIN DEPARTAMENTOS ACTIVOS
  |--------------------------------------------------------------------------
  |
  | Una respuesta exitosa sin activos
  | produce una lista vacía, no un error.
  |
  */

  it('should return an empty list when no department is active', () => {
    let result: Department[] | undefined;
    let errorCalled = false;

    service.listActive().subscribe({
      next: (departments) => {
        result = departments;
      },
      error: () => {
        errorCalled = true;
      },
    });

    httpMock
      .expectOne((req) => req.url === endpoint)
      .flush(inactiveOnlyResponse);

    expect(result).toEqual([]);
    expect(errorCalled).toBe(false);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 7 - OBSERVABLE SIN SUSCRIPCIÓN
  |--------------------------------------------------------------------------
  |
  | La solicitud no se envía hasta
  | que alguien se suscriba.
  |
  */

  it('should not send a request before subscription', () => {
    const operation = service.listActive();

    httpMock.expectNone(endpoint);

    operation.subscribe();

    httpMock
      .expectOne((req) => req.url === endpoint)
      .flush(mockResponse);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 8 - ERROR 401
  |--------------------------------------------------------------------------
  |
  | Una sesión inválida no debe
  | convertirse en una lista vacía.
  |
  */

  it('should propagate unauthorized errors', () => {
    let receivedError: HttpErrorResponse | undefined;
    let successCalled = false;

    service.listActive().subscribe({
      next: () => {
        successCalled = true;
      },
      error: (error: HttpErrorResponse) => {
        receivedError = error;
      },
    });

    httpMock
      .expectOne((req) => req.url === endpoint)
      .flush(
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
  | PRUEBA 9 - ERROR 403
  |--------------------------------------------------------------------------
  |
  | Un usuario sin departments.view
  | recibe 403. El formulario debe saber
  | que fue un problema de permisos y no
  | que "no existen departamentos".
  |
  */

  it('should propagate forbidden errors instead of returning an empty list', () => {
    let receivedError: HttpErrorResponse | undefined;
    let successCalled = false;

    service.listActive().subscribe({
      next: () => {
        successCalled = true;
      },
      error: (error: HttpErrorResponse) => {
        receivedError = error;
      },
    });

    httpMock
      .expectOne((req) => req.url === endpoint)
      .flush(
        {
          message: 'This action is unauthorized.',
        },
        {
          status: 403,
          statusText: 'Forbidden',
        }
      );

    expect(successCalled).toBe(false);
    expect(receivedError?.status).toBe(403);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 10 - ERROR DE CONEXIÓN
  |--------------------------------------------------------------------------
  |
  | Si Laravel no está disponible,
  | el servicio entrega el error.
  |
  */

  it('should propagate network errors', () => {
    let receivedError: HttpErrorResponse | undefined;

    service.listActive().subscribe({
      error: (error: HttpErrorResponse) => {
        receivedError = error;
      },
    });

    httpMock
      .expectOne((req) => req.url === endpoint)
      .error(new ProgressEvent('error'));

    expect(receivedError).toBeInstanceOf(
      HttpErrorResponse
    );

    expect(receivedError?.status).toBe(0);
  });

});