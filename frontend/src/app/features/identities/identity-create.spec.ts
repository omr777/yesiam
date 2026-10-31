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
  IdentityCreate,
} from './identity-create';

import type {
  CreateIdentityResponse,
} from './identity.service';

import type {
  DepartmentsResponse,
} from '../departments/department.service';

/*
|--------------------------------------------------------------------------
| YESIAM - IDENTITY CREATE TESTS (JOINER)
|--------------------------------------------------------------------------
|
| FASE 19.5.5
|
| Pruebas del componente IdentityCreate.
|
| Validamos:
|
| - Carga de departamentos activos.
| - Estados vacíos y errores del catálogo.
| - Validaciones del formulario.
| - Enlace entre el DOM y el formulario.
| - Envío de los campos permitidos.
| - Protección contra envíos dobles.
| - Resumen de la identidad creada.
| - Errores 401, 403, 419, 422 y de conexión.
|
| No se realizan peticiones HTTP reales.
|
*/

describe('IdentityCreate', () => {
  let fixture: ComponentFixture<IdentityCreate>;
  let component: IdentityCreate;
  let httpMock: HttpTestingController;

  /*
  |--------------------------------------------------------------------------
  | DATOS SIMULADOS
  |--------------------------------------------------------------------------
  |
  | Activos: Administración (1) y
  |          Recursos Humanos (3).
  |
  | Inactivos: Finanzas (2) y Tecnología (4).
  |
  */

  const departmentsResponse: DepartmentsResponse = {
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
    ],
  };

  const createResponse: CreateIdentityResponse = {
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
  | AYUDAS
  |--------------------------------------------------------------------------
  */

  function expectDepartmentsRequest(): TestRequest {
    return httpMock.expectOne(
      (request) =>
        request.method === 'GET' &&
        request.url === '/api/departments'
    );
  }

  function expectCreateRequest(): TestRequest {
    return httpMock.expectOne(
      (request) =>
        request.method === 'POST' &&
        request.url === '/api/users'
    );
  }

  /*
   * Responde a la carga inicial
   * de departamentos.
   */
  function loadDepartments(
    response: DepartmentsResponse = departmentsResponse
  ): void {
    expectDepartmentsRequest().flush(response);

    fixture.detectChanges();
  }

  /*
   * Completa el formulario con
   * datos válidos.
   */
  function fillValidForm(): void {
    component.form.setValue({
      name: 'Elena Martínez',
      email: 'elena@example.test',
      department_id: 3,
    });
  }

  /*
   * Envía el formulario desde el DOM,
   * igual que al pulsar "Crear identidad".
   */
  function submitForm(): void {
    const form: HTMLFormElement =
      fixture.nativeElement.querySelector('form');

    form.dispatchEvent(new Event('submit'));

    fixture.detectChanges();
  }

  function text(): string {
    return fixture.nativeElement.textContent;
  }

  /*
  |--------------------------------------------------------------------------
  | PREPARACIÓN
  |--------------------------------------------------------------------------
  */

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [
        IdentityCreate,
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
      IdentityCreate
    );

    component = fixture.componentInstance;
  });

  afterEach(() => {
    fixture.destroy();

    httpMock.verify();
  });

  /*
  |--------------------------------------------------------------------------
  | SECCIÓN A - CATÁLOGO DE DEPARTAMENTOS
  |--------------------------------------------------------------------------
  */

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 1 - CARGA INICIAL
  |--------------------------------------------------------------------------
  */

  it('should load departments before showing the form', () => {
    fixture.detectChanges();

    expect(component.isLoadingDepartments()).toBe(true);

    expect(text()).toContain('Cargando departamentos');

    expect(
      fixture.nativeElement.querySelector('form')
    ).toBeNull();

    const request = expectDepartmentsRequest();

    expect(request.request.withCredentials).toBe(true);

    request.flush(departmentsResponse);

    fixture.detectChanges();

    expect(component.isLoadingDepartments()).toBe(false);

    expect(
      fixture.nativeElement.querySelector('form')
    ).not.toBeNull();
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 2 - SOLO DEPARTAMENTOS ACTIVOS
  |--------------------------------------------------------------------------
  */

  it('should offer only active departments in the selector', () => {
    loadDepartments();

    const options: NodeListOf<HTMLOptionElement> =
      fixture.nativeElement.querySelectorAll(
        '#joiner-department option'
      );

    /*
     * Opción inicial + 2 departamentos activos.
     */
    expect(options.length).toBe(3);

    expect(options[0].disabled).toBe(true);

    expect(options[1].textContent).toContain(
      'Administración (ADMIN)'
    );

    expect(options[2].textContent).toContain(
      'Recursos Humanos (RH)'
    );

    const selectText: string =
      fixture.nativeElement.querySelector(
        '#joiner-department'
      ).textContent;

    expect(selectText).not.toContain('Finanzas');
    expect(selectText).not.toContain('Tecnología');
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 3 - SIN DEPARTAMENTOS ACTIVOS
  |--------------------------------------------------------------------------
  */

  it('should block the Joiner when there are no active departments', () => {
    loadDepartments({
      data: [
        {
          id: 2,
          name: 'Finanzas',
          code: 'FIN',
          is_active: false,
        },
      ],
    });

    expect(text()).toContain(
      'No existen departamentos activos'
    );

    expect(
      fixture.nativeElement.querySelector('form')
    ).toBeNull();
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 4 - CATÁLOGO: ERROR 403
  |--------------------------------------------------------------------------
  */

  it('should show a permission error when departments are forbidden', () => {
    expectDepartmentsRequest().flush(
      {
        message: 'This action is unauthorized.',
      },
      {
        status: 403,
        statusText: 'Forbidden',
      }
    );

    fixture.detectChanges();

    expect(component.departmentsErrorStatus()).toBe(403);

    expect(text()).toContain(
      'No tienes permisos para consultar los departamentos'
    );

    /*
     * Un 403 no se resuelve reintentando.
     */
    expect(
      fixture.nativeElement.querySelector(
        '.notice.error button'
      )
    ).toBeNull();

    expect(
      fixture.nativeElement.querySelector('form')
    ).toBeNull();
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 5 - CATÁLOGO: REINTENTO
  |--------------------------------------------------------------------------
  */

  it('should allow retrying after a department network failure', () => {
    expectDepartmentsRequest().error(
      new ProgressEvent('error')
    );

    fixture.detectChanges();

    expect(text()).toContain(
      'No fue posible conectar con YESIAM'
    );

    const retryButton: HTMLButtonElement =
      fixture.nativeElement.querySelector(
        '.notice.error button'
      );

    expect(retryButton).not.toBeNull();

    retryButton.click();

    fixture.detectChanges();

    loadDepartments();

    expect(component.departmentsErrorMessage()).toBeNull();

    expect(
      fixture.nativeElement.querySelector('form')
    ).not.toBeNull();
  });

  /*
  |--------------------------------------------------------------------------
  | SECCIÓN B - VALIDACIONES DEL FORMULARIO
  |--------------------------------------------------------------------------
  */

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 6 - FORMULARIO VACÍO
  |--------------------------------------------------------------------------
  */

  it('should not send a request when the form is empty', () => {
    loadDepartments();

    submitForm();

    httpMock.expectNone(
      (request) => request.method === 'POST'
    );

    expect(text()).toContain('Escribe el nombre completo');
    expect(text()).toContain('Escribe el correo electrónico');
    expect(text()).toContain('Selecciona un departamento.');
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 7 - NOMBRE EN BLANCO Y CORREO INVÁLIDO
  |--------------------------------------------------------------------------
  */

  it('should reject a blank name and an invalid email', () => {
    loadDepartments();

    component.form.setValue({
      name: '    ',
      email: 'correo-invalido',
      department_id: 1,
    });

    submitForm();

    httpMock.expectNone(
      (request) => request.method === 'POST'
    );

    expect(
      component.form.controls.name.hasError('blank')
    ).toBe(true);

    expect(text()).toContain('Escribe el nombre completo');

    expect(text()).toContain(
      'Escribe un correo electrónico válido'
    );
  });

  /*
  |--------------------------------------------------------------------------
  | SECCIÓN C - ENVÍO DEL JOINER
  |--------------------------------------------------------------------------
  */

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 8 - CAPTURA DESDE EL DOM
  |--------------------------------------------------------------------------
  |
  | Simula a un administrador escribiendo
  | y eligiendo el departamento por nombre.
  |
  */

  it('should send the values captured in the form controls', () => {
    loadDepartments();

    const nameInput: HTMLInputElement =
      fixture.nativeElement.querySelector('#joiner-name');

    const emailInput: HTMLInputElement =
      fixture.nativeElement.querySelector('#joiner-email');

    const departmentSelect: HTMLSelectElement =
      fixture.nativeElement.querySelector(
        '#joiner-department'
      );

    nameInput.value = 'Elena Martínez';
    nameInput.dispatchEvent(new Event('input'));

    emailInput.value = 'elena@example.test';
    emailInput.dispatchEvent(new Event('input'));

    /*
     * Índice 2 = Recursos Humanos (id 3).
     */
    departmentSelect.selectedIndex = 2;
    departmentSelect.dispatchEvent(new Event('change'));

    fixture.detectChanges();

    submitForm();

    const request = expectCreateRequest();

    expect(request.request.body).toEqual({
      name: 'Elena Martínez',
      email: 'elena@example.test',
      department_id: 3,
    });

    request.flush(createResponse, {
      status: 201,
      statusText: 'Created',
    });
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 9 - SOLO CAMPOS PERMITIDOS
  |--------------------------------------------------------------------------
  |
  | Angular no envía password, status
  | ni roles, y recorta espacios.
  |
  */

  it('should send only the allowed Joiner fields with trimmed values', () => {
    loadDepartments();

    component.form.setValue({
      name: '   Elena Martínez   ',
      email: '  elena@example.test  ',
      department_id: 3,
    });

    submitForm();

    const request = expectCreateRequest();

    const body = request.request.body as Record<
      string,
      unknown
    >;

    expect(Object.keys(body).sort()).toEqual([
      'department_id',
      'email',
      'name',
    ]);

    expect(body['name']).toBe('Elena Martínez');
    expect(body['email']).toBe('elena@example.test');
    expect(body['department_id']).toBe(3);

    expect(body['password']).toBeUndefined();
    expect(body['status']).toBeUndefined();
    expect(body['roles']).toBeUndefined();

    expect(request.request.withCredentials).toBe(true);

    request.flush(createResponse, {
      status: 201,
      statusText: 'Created',
    });
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 10 - ENVÍOS DOBLES
  |--------------------------------------------------------------------------
  */

  it('should prevent duplicate submissions while creating', () => {
    loadDepartments();

    fillValidForm();

    submitForm();

    expect(component.isSubmitting()).toBe(true);

    const fieldset: HTMLFieldSetElement =
      fixture.nativeElement.querySelector('fieldset');

    expect(fieldset.disabled).toBe(true);

    expect(text()).toContain('Creando identidad');

    /*
     * Segundo intento mientras la
     * primera solicitud sigue pendiente.
     */
    component.submit();

    /*
     * expectOne falla si existieran
     * dos solicitudes POST.
     */
    const request = expectCreateRequest();

    request.flush(createResponse, {
      status: 201,
      statusText: 'Created',
    });

    fixture.detectChanges();

    expect(component.isSubmitting()).toBe(false);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 11 - RESUMEN DE LA IDENTIDAD CREADA
  |--------------------------------------------------------------------------
  */

  it('should show the identity created by Laravel', () => {
    loadDepartments();

    fillValidForm();

    submitForm();

    expectCreateRequest().flush(createResponse, {
      status: 201,
      statusText: 'Created',
    });

    fixture.detectChanges();

    expect(component.createdIdentity()).toEqual(
      createResponse.data
    );

    expect(text()).toContain(
      'Identidad creada correctamente'
    );

    expect(text()).toContain('Elena Martínez');
    expect(text()).toContain('elena@example.test');
    expect(text()).toContain('Recursos Humanos');
    expect(text()).toContain('Suspendido');
    expect(text()).toContain('employee');

    expect(
      fixture.nativeElement.querySelector('form')
    ).toBeNull();

    const directoryLink: HTMLAnchorElement | null =
      fixture.nativeElement.querySelector(
        '.notice.success a[href="/identities"]'
      );

    expect(directoryLink).not.toBeNull();
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 12 - REGISTRAR OTRA IDENTIDAD
  |--------------------------------------------------------------------------
  */

  it('should reset the form to register another identity', () => {
    loadDepartments();

    fillValidForm();

    submitForm();

    expectCreateRequest().flush(createResponse, {
      status: 201,
      statusText: 'Created',
    });

    fixture.detectChanges();

    const newButton: HTMLButtonElement =
      fixture.nativeElement.querySelector(
        '.notice.success button'
      );

    newButton.click();

    fixture.detectChanges();

    expect(component.createdIdentity()).toBeNull();

    expect(component.form.getRawValue()).toEqual({
      name: '',
      email: '',
      department_id: null,
    });

    expect(
      fixture.nativeElement.querySelector('form')
    ).not.toBeNull();
  });

  /*
  |--------------------------------------------------------------------------
  | SECCIÓN D - ERRORES DE CREACIÓN
  |--------------------------------------------------------------------------
  */

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 13 - ERROR 422: CORREO DUPLICADO
  |--------------------------------------------------------------------------
  */

  it('should mark the email field on a 422 without exposing backend messages', () => {
    loadDepartments();

    fillValidForm();

    submitForm();

    expectCreateRequest().flush(
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

    fixture.detectChanges();

    expect(component.submitErrorStatus()).toBe(422);

    expect(text()).toContain('Revisa los campos marcados');

    expect(text()).toContain(
      'El correo no es válido o ya está registrado'
    );

    expect(text()).not.toContain(
      'The email has already been taken'
    );

    /*
     * Al corregir el campo, el error
     * del servidor desaparece.
     */
    const emailInput: HTMLInputElement =
      fixture.nativeElement.querySelector('#joiner-email');

    emailInput.value = 'elena.martinez@example.test';
    emailInput.dispatchEvent(new Event('input'));

    fixture.detectChanges();

    expect(component.serverErrors().email).toBeUndefined();

    expect(text()).not.toContain(
      'El correo no es válido o ya está registrado'
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 14 - ERROR 422: DEPARTAMENTO DESACTIVADO
  |--------------------------------------------------------------------------
  |
  | El departamento se desactivó mientras
  | el administrador llenaba el formulario.
  |
  */

  it('should reload departments when Laravel rejects the department', () => {
    loadDepartments();

    fillValidForm();

    submitForm();

    expectCreateRequest().flush(
      {
        message: 'The selected department id is invalid.',
        errors: {
          department_id: [
            'The selected department id is invalid.',
          ],
        },
      },
      {
        status: 422,
        statusText: 'Unprocessable Content',
      }
    );

    fixture.detectChanges();

    /*
     * Recursos Humanos (3) ya no está activo.
     */
    loadDepartments({
      data: [
        {
          id: 1,
          name: 'Administración',
          code: 'ADMIN',
          is_active: true,
        },
        {
          id: 3,
          name: 'Recursos Humanos',
          code: 'RH',
          is_active: false,
        },
      ],
    });

    expect(
      component.form.controls.department_id.value
    ).toBeNull();

    expect(component.departments().length).toBe(1);

    expect(text()).toContain(
      'El departamento ya no existe o no está activo'
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 15 - ERROR 403
  |--------------------------------------------------------------------------
  */

  it('should show a permission error when the user cannot create identities', () => {
    loadDepartments();

    fillValidForm();

    submitForm();

    expectCreateRequest().flush(
      {
        message: 'This action is unauthorized.',
      },
      {
        status: 403,
        statusText: 'Forbidden',
      }
    );

    fixture.detectChanges();

    expect(component.submitErrorStatus()).toBe(403);

    expect(text()).toContain(
      'No tienes permisos para registrar identidades'
    );

    expect(component.createdIdentity()).toBeNull();
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 16 - ERRORES 401 Y 419
  |--------------------------------------------------------------------------
  |
  | Sesión vencida o token CSRF expirado:
  | se ofrece volver a iniciar sesión.
  |
  */

  it('should offer login guidance on 401 and 419 errors', () => {
    loadDepartments();

    fillValidForm();

    submitForm();

    expectCreateRequest().flush(
      {
        message: 'Unauthenticated.',
      },
      {
        status: 401,
        statusText: 'Unauthorized',
      }
    );

    fixture.detectChanges();

    expect(text()).toContain('Inicia sesión nuevamente');

    expect(
      fixture.nativeElement.querySelector(
        '.notice.error a[href="/sign-in"]'
      )
    ).not.toBeNull();

    /*
     * Segundo intento: CSRF vencido.
     */
    submitForm();

    expectCreateRequest().flush(
      {
        message: 'CSRF token mismatch.',
      },
      {
        status: 419,
        statusText: 'Page Expired',
      }
    );

    fixture.detectChanges();

    expect(component.submitErrorStatus()).toBe(419);

    expect(text()).toContain(
      'La sesión de seguridad expiró'
    );

    expect(
      fixture.nativeElement.querySelector(
        '.notice.error a[href="/sign-in"]'
      )
    ).not.toBeNull();
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 17 - ERROR DE CONEXIÓN
  |--------------------------------------------------------------------------
  |
  | El formulario vuelve a habilitarse
  | y conserva los datos capturados.
  |
  */

  it('should re-enable the form after a network error', () => {
    loadDepartments();

    fillValidForm();

    submitForm();

    expectCreateRequest().error(
      new ProgressEvent('error')
    );

    fixture.detectChanges();

    expect(component.isSubmitting()).toBe(false);

    expect(text()).toContain(
      'No fue posible conectar con YESIAM'
    );

    const fieldset: HTMLFieldSetElement =
      fixture.nativeElement.querySelector('fieldset');

    expect(fieldset.disabled).toBe(false);

    expect(component.form.getRawValue()).toEqual({
      name: 'Elena Martínez',
      email: 'elena@example.test',
      department_id: 3,
    });
  });

});