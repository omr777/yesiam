import {
  ComponentFixture,
  TestBed,
} from '@angular/core/testing';

import {
  provideHttpClient,
} from '@angular/common/http';

import {
  HttpTestingController,
  provideHttpClientTesting,
  TestRequest,
} from '@angular/common/http/testing';

import {
  provideRouter,
} from '@angular/router';

import {
  IdentityList,
} from './identity-list';

import type {
  Identity,
  PaginatedIdentitiesResponse,
} from './identity.service';

/*
|--------------------------------------------------------------------------
| YESIAM - IDENTITY LIST TESTS
|--------------------------------------------------------------------------
|
| FASE 19.5.8
|
| Pruebas del componente IdentityList.
|
| 19.5.8: acceso al formulario Joiner.
|
| Validamos:
|
| - Carga inicial.
| - Solicitudes HTTP.
| - Visualización de identidades.
| - Resultados vacíos.
| - Búsqueda.
| - Filtros por estado.
| - Filtros combinados.
| - Limpieza de filtros.
| - Paginación.
| - Límites de navegación.
| - Errores 401, 403 y 422.
| - Errores de conexión y reintentos.
| - Cancelación de solicitudes anteriores.
| - Enlace al alta de identidades (Joiner).
|
| No se realizan peticiones HTTP reales.
|
*/

describe('IdentityList', () => {
  let fixture: ComponentFixture<IdentityList>;
  let component: IdentityList;
  let httpMock: HttpTestingController;

  /*
  |--------------------------------------------------------------------------
  | IDENTIDADES SIMULADAS
  |--------------------------------------------------------------------------
  */

  const identities: Identity[] = [
    {
      id: 1,
      name: 'Ana Solís',
      email: 'ana@example.test',
      status: 'active',
      department_id: 2,
    },
    {
      id: 2,
      name: 'Carlos Rivera',
      email: 'carlos@example.test',
      status: 'suspended',
      department_id: null,
    },
  ];

  /*
  |--------------------------------------------------------------------------
  | RESPUESTA PAGINADA SIMULADA
  |--------------------------------------------------------------------------
  */

  function makeResponse(
    data: Identity[] = identities,
    overrides: Partial<PaginatedIdentitiesResponse> = {}
  ): PaginatedIdentitiesResponse {
    return {
      data,
      current_page: 1,
      last_page: 1,
      per_page: 15,
      total: data.length,
      from: data.length > 0 ? 1 : null,
      to: data.length > 0 ? data.length : null,
      next_page_url: null,
      prev_page_url: null,
      ...overrides,
    };
  }

  /*
  |--------------------------------------------------------------------------
  | INTERCEPTAR PETICIÓN DEL LISTADO
  |--------------------------------------------------------------------------
  |
  | El componente consulta la API desde
  | su constructor.
  |
  | Por eso interceptamos la solicitud
  | inicial después de createComponent().
  |
  */

  function expectUsersRequest(): TestRequest {
    return httpMock.expectOne(
      (request) =>
        request.method === 'GET' &&
        request.url === '/api/users'
    );
  }

  /*
  |--------------------------------------------------------------------------
  | RESPONDER A LA CARGA INICIAL
  |--------------------------------------------------------------------------
  */

  function loadInitialData(
    response: PaginatedIdentitiesResponse = makeResponse()
  ): void {
    expectUsersRequest().flush(response);

    fixture.detectChanges();
  }

  /*
  |--------------------------------------------------------------------------
  | PREPARACIÓN
  |--------------------------------------------------------------------------
  */

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [
        IdentityList,
      ],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    httpMock = TestBed.inject(
      HttpTestingController
    );

    fixture = TestBed.createComponent(
      IdentityList
    );

    component = fixture.componentInstance;
  });

  afterEach(() => {
    fixture.destroy();

    httpMock.verify();
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 1 - CARGA INICIAL
  |--------------------------------------------------------------------------
  */

  it('should display loading state during initial request', () => {
    fixture.detectChanges();

    expect(component.isLoading()).toBe(true);

    expect(
      fixture.nativeElement.textContent
    ).toContain('Cargando identidades');

    loadInitialData();

    expect(component.isLoading()).toBe(false);

    expect(
      fixture.nativeElement.textContent
    ).not.toContain('Cargando identidades');
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 2 - CONSULTA CORRECTA
  |--------------------------------------------------------------------------
  */

  it('should request the first page using default filters', () => {
    const request = expectUsersRequest();

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

    expect(
      request.request.withCredentials
    ).toBe(true);

    request.flush(makeResponse());

    fixture.detectChanges();

    expect(component.total()).toBe(2);
    expect(component.currentPage()).toBe(1);
    expect(component.lastPage()).toBe(1);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 3 - MOSTRAR IDENTIDADES
  |--------------------------------------------------------------------------
  */

  it('should render identities returned by Laravel', () => {
    loadInitialData();

    const rows: NodeListOf<HTMLTableRowElement> =
      fixture.nativeElement.querySelectorAll(
        'tbody tr'
      );

    expect(rows.length).toBe(2);

    const content: string =
      fixture.nativeElement.textContent;

    expect(content).toContain('Ana Solís');
    expect(content).toContain('ana@example.test');

    expect(content).toContain('Carlos Rivera');
    expect(content).toContain('carlos@example.test');

    expect(content).toContain('Activo');
    expect(content).toContain('Suspendido');

    expect(content).toContain('Departamento #2');
    expect(content).toContain('Sin departamento');
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 4 - RESULTADOS VACÍOS
  |--------------------------------------------------------------------------
  */

  it('should show an empty state when no identities exist', () => {
    loadInitialData(
      makeResponse([])
    );

    expect(component.identities()).toEqual([]);
    expect(component.total()).toBe(0);

    expect(
      fixture.nativeElement.textContent
    ).toContain(
      'No se encontraron identidades'
    );

    expect(
      fixture.nativeElement.querySelector('tbody')
    ).toBeNull();
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 5 - BÚSQUEDA
  |--------------------------------------------------------------------------
  */

  it('should search identities and reset pagination', () => {
    loadInitialData();

    component.currentPage.set(3);

    component.searchText = '  Ana Solís  ';

    component.applySearch();

    const request = expectUsersRequest();

    expect(
      request.request.params.get('search')
    ).toBe('Ana Solís');

    expect(
      request.request.params.get('page')
    ).toBe('1');

    request.flush(
      makeResponse([identities[0]])
    );

    fixture.detectChanges();

    expect(component.currentPage()).toBe(1);
    expect(component.identities().length).toBe(1);

    expect(
      fixture.nativeElement.textContent
    ).toContain('Ana Solís');
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 6 - FILTRAR POR ESTADO
  |--------------------------------------------------------------------------
  */

  it('should filter identities by status', () => {
    loadInitialData();

    component.selectedStatus = 'suspended';

    component.onStatusChange();

    const request = expectUsersRequest();

    expect(
      request.request.params.get('status')
    ).toBe('suspended');

    expect(
      request.request.params.get('page')
    ).toBe('1');

    request.flush(
      makeResponse([identities[1]])
    );

    fixture.detectChanges();

    expect(component.identities().length).toBe(1);

    expect(
      fixture.nativeElement.textContent
    ).toContain('Carlos Rivera');
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 7 - COMBINAR FILTROS
  |--------------------------------------------------------------------------
  */

  it('should combine search and status filters', () => {
    loadInitialData();

    component.searchText = ' Carlos ';
    component.selectedStatus = 'suspended';

    component.applySearch();

    const request = expectUsersRequest();

    expect(
      request.request.params.get('search')
    ).toBe('Carlos');

    expect(
      request.request.params.get('status')
    ).toBe('suspended');

    request.flush(
      makeResponse([identities[1]])
    );

    fixture.detectChanges();

    expect(component.total()).toBe(1);
    expect(component.identities()[0].id).toBe(2);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 8 - LIMPIAR FILTROS
  |--------------------------------------------------------------------------
  */

  it('should clear all filters and reload identities', () => {
    loadInitialData();

    component.searchText = 'Ana';
    component.selectedStatus = 'active';

    component.applySearch();

    expectUsersRequest().flush(
      makeResponse([identities[0]])
    );

    fixture.detectChanges();

    component.resetFilters();

    expect(component.searchText).toBe('');
    expect(component.selectedStatus).toBe('');

    const request = expectUsersRequest();

    expect(
      request.request.params.has('search')
    ).toBe(false);

    expect(
      request.request.params.has('status')
    ).toBe(false);

    expect(
      request.request.params.get('page')
    ).toBe('1');

    request.flush(makeResponse());

    fixture.detectChanges();

    expect(component.identities().length).toBe(2);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 9 - CAMBIAR DE PÁGINA
  |--------------------------------------------------------------------------
  */

  it('should navigate between available pages', () => {
    loadInitialData(
      makeResponse(identities, {
        total: 25,
        last_page: 2,
        current_page: 1,
      })
    );

    component.goToPage(2);

    const secondPageRequest = expectUsersRequest();

    expect(
      secondPageRequest.request.params.get('page')
    ).toBe('2');

    secondPageRequest.flush(
      makeResponse([identities[1]], {
        current_page: 2,
        last_page: 2,
        total: 25,
        from: 16,
        to: 16,
      })
    );

    fixture.detectChanges();

    expect(component.currentPage()).toBe(2);
    expect(component.lastPage()).toBe(2);

    expect(
      fixture.nativeElement.textContent
    ).toContain('Página 2 de 2');

    component.goToPage(1);

    const firstPageRequest = expectUsersRequest();

    expect(
      firstPageRequest.request.params.get('page')
    ).toBe('1');

    firstPageRequest.flush(
      makeResponse(identities, {
        total: 25,
        last_page: 2,
      })
    );

    fixture.detectChanges();

    expect(component.currentPage()).toBe(1);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 10 - LÍMITES DE PAGINACIÓN
  |--------------------------------------------------------------------------
  */

  it('should reject navigation outside available pages', () => {
    loadInitialData();

    component.goToPage(0);
    component.goToPage(-1);
    component.goToPage(2);

    httpMock.expectNone(
      (request) => request.url === '/api/users'
    );

    expect(component.currentPage()).toBe(1);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 11 - ERROR 401
  |--------------------------------------------------------------------------
  */

  it('should show login guidance when session expires', () => {
    expectUsersRequest().flush(
      {
        message: 'Unauthenticated.',
      },
      {
        status: 401,
        statusText: 'Unauthorized',
      }
    );

    fixture.detectChanges();

    expect(component.errorStatus()).toBe(401);

    expect(
      fixture.nativeElement.textContent
    ).toContain('Inicia sesión nuevamente');

    const loginLink: HTMLAnchorElement | null =
      fixture.nativeElement.querySelector(
        'a[href="/sign-in"]'
      );

    expect(loginLink).not.toBeNull();

    expect(
      fixture.nativeElement.querySelector('tbody')
    ).toBeNull();
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 12 - ERROR 403
  |--------------------------------------------------------------------------
  */

  it('should show access denied when user lacks permission', () => {
    expectUsersRequest().flush(
      {
        message: 'Forbidden.',
      },
      {
        status: 403,
        statusText: 'Forbidden',
      }
    );

    fixture.detectChanges();

    expect(component.errorStatus()).toBe(403);

    expect(
      fixture.nativeElement.textContent
    ).toContain(
      'No tienes permisos para consultar'
    );

    expect(component.identities()).toEqual([]);

    /*
     * La pantalla no debe ofrecer un
     * botón de reintento para un 403.
     */
    expect(
      fixture.nativeElement.querySelector(
        '.notice.error button'
      )
    ).toBeNull();
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 13 - ERROR 422
  |--------------------------------------------------------------------------
  */

  it('should show validation errors without exposing backend details', () => {
    expectUsersRequest().flush(
      {
        message: 'Validation failed.',
        errors: {
          page: ['Invalid page.'],
        },
      },
      {
        status: 422,
        statusText: 'Unprocessable Content',
      }
    );

    fixture.detectChanges();

    expect(component.errorStatus()).toBe(422);

    expect(
      fixture.nativeElement.textContent
    ).toContain(
      'Los filtros enviados no son válidos'
    );

    expect(
      fixture.nativeElement.textContent
    ).not.toContain('Validation failed.');
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 14 - ERROR DE RED Y REINTENTO
  |--------------------------------------------------------------------------
  */

  it('should allow retrying after a network failure', () => {
    const request = expectUsersRequest();

    request.error(
      new ProgressEvent('error')
    );

    fixture.detectChanges();

    expect(component.errorStatus()).toBe(0);

    expect(
      fixture.nativeElement.textContent
    ).toContain(
      'No fue posible conectar con YESIAM'
    );

    const retryButton: HTMLButtonElement | null =
      fixture.nativeElement.querySelector(
        '.notice.error button'
      );

    expect(retryButton).not.toBeNull();

    retryButton!.click();

    const retryRequest = expectUsersRequest();

    retryRequest.flush(makeResponse());

    fixture.detectChanges();

    expect(component.errorMessage()).toBeNull();
    expect(component.errorStatus()).toBeNull();
    expect(component.identities().length).toBe(2);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 15 - CANCELAR SOLICITUD ANTERIOR
  |--------------------------------------------------------------------------
  |
  | Si el usuario realiza una nueva búsqueda
  | antes de que termine la primera consulta,
  | la solicitud anterior debe cancelarse.
  |
  | Esto evita que una respuesta antigua
  | reemplace los resultados nuevos.
  |
  */

  it('should cancel the previous request when starting a new search', () => {
    const initialRequest = expectUsersRequest();

    /*
     * La primera consulta sigue pendiente.
     */
    expect(component.isLoading()).toBe(true);

    component.searchText = 'Nueva búsqueda';

    component.applySearch();

    /*
     * La suscripción anterior
     * debe estar cancelada.
     */
    expect(initialRequest.cancelled).toBe(true);

    const newRequest = expectUsersRequest();

    expect(
      newRequest.request.params.get('search')
    ).toBe('Nueva búsqueda');

    newRequest.flush(
      makeResponse([identities[0]])
    );

    fixture.detectChanges();

    expect(component.isLoading()).toBe(false);
    expect(component.identities().length).toBe(1);

    expect(component.identities()[0].id).toBe(1);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 16 - ACCESO AL JOINER
  |--------------------------------------------------------------------------
  |
  | El encabezado ofrece el acceso
  | al formulario de alta.
  |
  */

  it('should link to the Joiner form from the directory header', () => {
    loadInitialData();

    const joinerLink: HTMLAnchorElement | null =
      fixture.nativeElement.querySelector(
        '.heading a.new-identity'
      );

    expect(joinerLink).not.toBeNull();

    expect(
      joinerLink!.getAttribute('href')
    ).toBe('/identities/new');

    expect(
      joinerLink!.textContent
    ).toContain('Nueva identidad');
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 17 - OCULTAR JOINER SIN PERMISOS
  |--------------------------------------------------------------------------
  |
  | El directorio y el alta requieren
  | users.manage. Si Laravel respondió 403,
  | no ofrecemos una acción que también
  | será rechazada.
  |
  | Laravel sigue siendo quien autoriza.
  |
  */

  it('should hide the Joiner link when the directory is forbidden', () => {
    expectUsersRequest().flush(
      {
        message: 'Forbidden.',
      },
      {
        status: 403,
        statusText: 'Forbidden',
      }
    );

    fixture.detectChanges();

    expect(component.errorStatus()).toBe(403);

    expect(
      fixture.nativeElement.querySelector(
        'a.new-identity'
      )
    ).toBeNull();
  });
});